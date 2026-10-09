<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\CharsetConverter;
use Contenir\Mail\Header\ContentDisposition;
use Contenir\Mail\Header\ContentTransferEncoding;
use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Header\SafeText;
use Contenir\Mail\Headers;
use Contenir\Mail\Mime\PartInterface;
use Contenir\Mail\Mime\TransferEncoding;
use Contenir\Mail\Storage\Part\BodySelector;
use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\Decoder;
use Contenir\Mail\Storage\Part\MimeParser;
use Contenir\Mail\Storage\Part\MultipartSplitter;
use Contenir\Mail\Utf8;
use IteratorAggregate;
use Override;

use function count;
use function in_array;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtolower;

/**
 * A MIME part of a stored message: a leaf with content, or a multipart holding other parts.
 *
 * Read parts implement the same PartInterface as composed ones, so a stored
 * message or any of its parts can be attached or forwarded as it is.
 *
 * Parts load lazily. Headers are read with the part; the body is read from
 * the file, or fetched from the server, only when content or child parts
 * are asked for, and a multipart is split into its parts once, on first use.
 *
 * Hostile input is bounded: parts nest at most MAX_DEPTH deep, a multipart
 * holds at most MultipartSplitter::MAX_PARTS parts, and a multipart without a
 * boundary parameter is read as a leaf.
 *
 * @implements IteratorAggregate<int, Part>
 *
 * @mago-expect lint:too-many-methods PartInterface, the laminas-mail part accessors, and the content-type helpers.
 * @mago-expect lint:cyclomatic-complexity Each accessor falls back for parts whose headers are missing or malformed.
 *
 * @api
 */
final class Part implements PartInterface, IteratorAggregate
{
    /** Deepest nesting of multiparts read */
    public const int MAX_DEPTH = 32;

    /** @var list<Part>|null */
    private ?array $parts = null;

    /**
     * @internal Storage classes and fromString() build parts.
     */
    public function __construct(
        private readonly Headers $headers,
        private readonly Content $body,
        private readonly int $depth = 0,
    ) {}

    /**
     * Read a part, or a whole message, from its text.
     *
     * @throws Exception\RuntimeException When the header block is too large or malformed.
     */
    public static function fromString(string $raw): self
    {
        [$headers, $body] = MimeParser::split(Content::fromString($raw));

        return new self($headers, $body);
    }

    #[Override]
    public function getHeaders(): Headers
    {
        return $this->headers;
    }

    /**
     * The lower-cased media type, "text/plain" when there is no valid Content-Type (RFC 2045, section 5.2).
     */
    public function getContentType(): string
    {
        return strtolower($this->contentType()?->getType() ?? 'text/plain');
    }

    public function getCharset(): ?string
    {
        return $this->contentType()?->getParameter('charset');
    }

    /**
     * The file name the sender gave: the Content-Disposition filename, or the Content-Type name.
     *
     * This is untrusted input that may hold path separators, "..", control
     * or bidirectional characters; use getSafeFilename() to store or show it.
     */
    public function getFilename(): ?string
    {
        $disposition = $this->headers->get('Content-Disposition');
        $filename    = $disposition instanceof ContentDisposition ? $disposition->getFilename() : null;

        return $filename ?? $this->contentType()?->getParameter('name');
    }

    /**
     * The file name reduced to a safe base name, see SafeText::filename(); null when there is none.
     */
    public function getSafeFilename(): ?string
    {
        $filename = $this->getFilename();

        return null === $filename ? null : SafeText::filename($filename);
    }

    #[Override]
    public function isMultipart(): bool
    {
        return null !== $this->boundary();
    }

    /**
     * @return list<Part>
     * @throws Exception\RuntimeException When the parts nest too deeply, are too many, or cannot be read.
     */
    #[Override]
    public function getParts(): array
    {
        if (null !== $this->parts) {
            return $this->parts;
        }

        $boundary = $this->boundary();
        if (null === $boundary) {
            return $this->parts = [];
        }

        if ($this->depth >= self::MAX_DEPTH) {
            throw new Exception\RuntimeException(sprintf('Parts may nest at most %d deep', self::MAX_DEPTH));
        }

        $parts = [];
        foreach (MultipartSplitter::split($this->body, $boundary) as $raw) {
            [$headers, $body] = MimeParser::split($raw);
            $parts[] = new self($headers, $body, $this->depth + 1);
        }

        return $this->parts = $parts;
    }

    /**
     * A child part by number, the first being 1, as in laminas-mail.
     *
     * @throws Exception\OutOfBoundsException When there is no such part.
     * @throws Exception\RuntimeException When the parts cannot be read.
     */
    public function getPart(int $number): self
    {
        return $this->getParts()[$number - 1] ?? throw new Exception\OutOfBoundsException(sprintf(
            'There is no part %d',
            $number,
        ));
    }

    /**
     * @throws Exception\RuntimeException When the parts cannot be read.
     */
    public function countParts(): int
    {
        return count($this->getParts());
    }

    /**
     * The content, decoded from its Content-Transfer-Encoding; empty for a multipart.
     *
     * Bytes are returned in the part's own charset, see getCharset().
     *
     * @throws Exception\RuntimeException When the storage has been closed.
     */
    #[Override]
    public function getContent(): string
    {
        return Decoder::join($this->decoded());
    }

    /**
     * Write the content, decoded as getContent() decodes it, to a stream, a block at a time.
     *
     * The content is never held whole, so this is the way to save a large
     * attachment to a file. Nothing is written for a multipart.
     *
     * @param resource $stream An open, writable stream.
     * @return int The number of bytes written.
     * @throws Exception\RuntimeException When the storage has been closed, or the stream cannot be written.
     *
     * @mago-expect analysis:missing-parameter-type Streams have no native parameter type.
     */
    public function saveTo($stream): int
    {
        return Decoder::write($this->decoded(), $stream);
    }

    /**
     * The plain-text body as UTF-8, or null when there is no text/plain part.
     *
     * This is the first text/plain part that is not an attachment, found
     * through multipart/mixed, related and alternative parts. The text is
     * returned as sent; it is not sanitised.
     *
     * @throws Exception\RuntimeException When the parts cannot be read.
     */
    public function getTextBody(): ?string
    {
        return $this->body('text/plain');
    }

    /**
     * The HTML body as UTF-8, or null when there is no text/html part.
     *
     * Found as getTextBody() finds the text, preferring the last of the
     * alternatives. The HTML is returned as sent and is neither sanitised
     * nor safe to show: sanitise it before rendering.
     *
     * @throws Exception\RuntimeException When the parts cannot be read.
     */
    public function getHtmlBody(): ?string
    {
        return $this->body('text/html');
    }

    /**
     * The attachments and body of the first TNEF part, this part included, or null when there is none.
     *
     * A TNEF part is an application/ms-tnef or application/vnd.ms-tnef part,
     * or a part whose file name is winmail.dat. Parts are searched depth
     * first, in order. Pass a reader to change its limits.
     *
     * @throws Exception\RuntimeException When the parts cannot be read, or the TNEF part is malformed
     *     or exceeds the reader's limits.
     */
    public function getTnefContents(?Tnef\Reader $reader = null): ?Tnef\Contents
    {
        $part = self::findTnef($this);

        return null === $part ? null : ($reader ?? new Tnef\Reader())->read($part->getContent());
    }

    /**
     * The content as transferred, with CRLF line breaks; empty for a multipart.
     *
     * @throws Exception\RuntimeException When the storage has been closed.
     */
    #[Override]
    public function getEncodedContent(): string
    {
        return $this->isMultipart() ? '' : Decoder::crlf($this->body->read());
    }

    /**
     * The size of the body as stored, in bytes, without reading it.
     *
     * @throws Exception\RuntimeException When the storage has been closed.
     */
    public function getSize(): int
    {
        return $this->body->length();
    }

    /**
     * The part as written: its headers, as read where unchanged, a blank line and its body, with CRLF line breaks.
     *
     * @throws Exception\RuntimeException When the storage has been closed.
     */
    public function toString(): string
    {
        return $this->headers->toString() . Headers::EOL . Decoder::crlf($this->body->read());
    }

    /**
     * The child parts, keyed from 1; usable with RecursiveIteratorIterator to walk nested parts.
     *
     * @return TreeIterator<int, Part>
     * @throws Exception\RuntimeException When the parts cannot be read.
     */
    #[Override]
    public function getIterator(): TreeIterator
    {
        return new TreeIterator(self::children($this), self::children(...));
    }

    /**
     * A part's child parts, keyed from 1.
     *
     * @return array<int, Part>
     * @throws Exception\RuntimeException When the parts cannot be read.
     */
    private static function children(self $part): array
    {
        $numbered = [];
        foreach ($part->getParts() as $index => $child) {
            $numbered[$index + 1] = $child;
        }

        return $numbered;
    }

    /**
     * @throws Exception\RuntimeException When the parts cannot be read.
     */
    private function body(string $contentType): ?string
    {
        $part = BodySelector::find($this, $contentType);

        return null === $part
            ? null
            : Utf8::scrub(CharsetConverter::toUtf8($part->getContent(), $part->getCharset()));
    }

    /**
     * @throws Exception\RuntimeException When the parts cannot be read.
     */
    private static function findTnef(self $part): ?self
    {
        if ($part->isTnef()) {
            return $part;
        }

        foreach ($part->getParts() as $child) {
            $found = self::findTnef($child);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    private function isTnef(): bool
    {
        return (
            in_array($this->getContentType(), ['application/ms-tnef', 'application/vnd.ms-tnef'], strict: true)
                || 'winmail.dat' === strtolower($this->getSafeFilename() ?? '')
        );
    }

    private function contentType(): ?ContentType
    {
        $header = $this->headers->get('Content-Type');

        return $header instanceof ContentType ? $header : null;
    }

    private function boundary(): ?string
    {
        $type     = $this->contentType();
        $boundary = $type?->getParameter('boundary');
        if (null === $boundary || '' === $boundary || strlen($boundary) > 200) {
            return null;
        }

        return str_starts_with($this->getContentType(), 'multipart/') ? $boundary : null;
    }

    private function transferEncoding(): ?TransferEncoding
    {
        $header = $this->headers->get('Content-Transfer-Encoding');

        return $header instanceof ContentTransferEncoding ? $header->getTransferEncoding() : null;
    }

    /**
     * The content, decoded a block at a time; nothing for a multipart.
     *
     * @return iterable<string>
     * @throws Exception\RuntimeException When the storage has been closed.
     */
    private function decoded(): iterable
    {
        return $this->isMultipart() ? [] : Decoder::decode($this->body, $this->transferEncoding());
    }
}
