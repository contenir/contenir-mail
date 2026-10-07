<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

use Contenir\Mail\Header\ContentDisposition;
use Contenir\Mail\Header\ContentTransferEncoding;
use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Headers;
use Override;

use function base64_encode;
use function chunk_split;
use function feof;
use function fread;
use function get_resource_type;
use function is_resource;
use function is_string;
use function rewind;
use function rtrim;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function substr;

/**
 * A leaf of a MIME tree: text, HTML, an attachment or an inline resource.
 *
 * Content is a string, or a readable stream for large files. A stream is
 * read from the start each time the part is written, and base64 content
 * is encoded in chunks rather than read into memory in one piece.
 *
 * @mago-expect lint:too-many-methods Getters for each field, the PartInterface methods and serialization.
 * @mago-expect lint:cyclomatic-complexity Serialization checks each field's type.
 * @mago-expect lint:excessive-parameter-list A value object built with named arguments; every field is an optional MIME header.
 */
final readonly class Part implements PartInterface
{
    /** Bytes in one 72-character line of base64 */
    private const int BASE64_CHUNK = 54;

    /** @var string|resource */
    private mixed $content;

    /**
     * @param string|resource $content
     * @throws Exception\InvalidArgumentException When the content is neither a string nor a stream.
     */
    public function __construct(
        mixed $content,
        private string $type = Mime::TYPE_OCTETSTREAM,
        private TransferEncoding $encoding = TransferEncoding::Base64,
        private ?string $charset = null,
        private ?Disposition $disposition = null,
        private ?string $filename = null,
        private ?string $id = null,
        private ?string $description = null,
        private ?string $location = null,
        private ?string $language = null,
    ) {
        if (! is_string($content) && ! (is_resource($content) && 'stream' === get_resource_type($content))) {
            throw new Exception\InvalidArgumentException('Content must be a string or a stream');
        }

        $this->content = $content;
    }

    /**
     * The part's fields, with stream content read into a string so the part survives a queue.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'content'     => $this->getContent(),
            'type'        => $this->type,
            'encoding'    => $this->encoding,
            'charset'     => $this->charset,
            'disposition' => $this->disposition,
            'filename'    => $this->filename,
            'id'          => $this->id,
            'description' => $this->description,
            'location'    => $this->location,
            'language'    => $this->language,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws Exception\InvalidArgumentException When a field has the wrong type.
     *
     * @mago-expect analysis:invalid-property-write PHP lets __unserialize() initialise readonly properties once.
     * @mago-expect analysis:mixed-assignment Serialized data is untyped until it is checked here.
     */
    public function __unserialize(array $data): void
    {
        $content     = $data['content'] ?? null;
        $type        = $data['type'] ?? null;
        $encoding    = $data['encoding'] ?? null;
        $disposition = $data['disposition'] ?? null;
        if (
            ! is_string($content)
            || ! is_string($type)
            || ! $encoding instanceof TransferEncoding
            || (
                null !== $disposition
                && ! $disposition instanceof Disposition
            )
        ) {
            throw new Exception\InvalidArgumentException('Serialized part data is not valid');
        }

        $this->content     = $content;
        $this->type        = $type;
        $this->encoding    = $encoding;
        $this->disposition = $disposition;
        $this->charset     = self::nullableString($data, 'charset');
        $this->filename    = self::nullableString($data, 'filename');
        $this->id          = self::nullableString($data, 'id');
        $this->description = self::nullableString($data, 'description');
        $this->location    = self::nullableString($data, 'location');
        $this->language    = self::nullableString($data, 'language');
    }

    /**
     * Plain text, quoted-printable encoded.
     */
    public static function text(string $text, string $charset = 'UTF-8'): self
    {
        return new self($text, Mime::TYPE_TEXT, TransferEncoding::QuotedPrintable, $charset);
    }

    /**
     * HTML, quoted-printable encoded.
     */
    public static function html(string $html, string $charset = 'UTF-8'): self
    {
        return new self($html, Mime::TYPE_HTML, TransferEncoding::QuotedPrintable, $charset);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTransferEncoding(): TransferEncoding
    {
        return $this->encoding;
    }

    public function getCharset(): ?string
    {
        return $this->charset;
    }

    public function getDisposition(): ?Disposition
    {
        return $this->disposition;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    /**
     * The Content-ID, without angle brackets, that HTML refers to as "cid:…".
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    #[Override]
    public function getHeaders(): Headers
    {
        $headers = [
            new ContentType($this->type, null === $this->charset ? [] : ['charset' => $this->charset]),
            new ContentTransferEncoding($this->encoding),
        ];

        if (null !== $this->id) {
            $headers[] = new GenericHeader('Content-ID', "<{$this->id}>");
        }

        if (null !== $this->disposition) {
            $parameters = null === $this->filename ? [] : ['filename' => $this->filename];
            $headers[]  = new ContentDisposition($this->disposition->value, $parameters);
        }

        $optional = [
            'Content-Description' => $this->description,
            'Content-Location'    => $this->location,
            'Content-Language'    => $this->language,
        ];
        foreach ($optional as $name => $value) {
            if (null === $value) {
                continue;
            }

            $headers[] = new GenericHeader($name, $value);
        }

        return new Headers(...$headers);
    }

    #[Override]
    public function isMultipart(): bool
    {
        return false;
    }

    #[Override]
    public function getParts(): array
    {
        return [];
    }

    #[Override]
    public function getContent(): string
    {
        if (is_string($this->content)) {
            return $this->content;
        }

        rewind($this->content);

        return (string) stream_get_contents($this->content);
    }

    #[Override]
    public function getEncodedContent(): string
    {
        if (TransferEncoding::Base64 === $this->encoding && ! is_string($this->content)) {
            return self::encodeStreamAsBase64($this->content);
        }

        return Mime::encode(
            $this->getContent(),
            $this->encoding,
            Headers::EOL,
            text: str_starts_with($this->type, 'text/'),
        );
    }

    /**
     * Encode whole 54-byte groups as they are read, so padding only ever
     * appears at the very end, however the stream splits its reads.
     *
     * @param resource $stream
     *
     * @mago-expect analysis:missing-parameter-type Streams have no native parameter type.
     */
    private static function encodeStreamAsBase64($stream): string
    {
        rewind($stream);

        $encoded = '';
        $buffer  = '';
        while (! feof($stream)) {
            $buffer  .= (string) fread($stream, self::BASE64_CHUNK * 1024);
            $whole   = strlen($buffer) - (strlen($buffer) % self::BASE64_CHUNK);
            $encoded .= self::base64Lines(substr($buffer, offset: 0, length: $whole));
            $buffer  = substr($buffer, $whole);
        }

        return rtrim($encoded . self::base64Lines($buffer), Headers::EOL);
    }

    /**
     * Base64 in 72-character lines, each ending with a CRLF; nothing for no bytes.
     */
    private static function base64Lines(string $bytes): string
    {
        return '' === $bytes ? '' : chunk_split(base64_encode($bytes), Mime::LINELENGTH, Headers::EOL);
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws Exception\InvalidArgumentException When the field is neither null nor a string.
     *
     * @mago-expect analysis:mixed-assignment Serialized data is untyped until it is checked here.
     */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (null !== $value && ! is_string($value)) {
            throw new Exception\InvalidArgumentException('Serialized part data is not valid');
        }

        return $value;
    }
}
