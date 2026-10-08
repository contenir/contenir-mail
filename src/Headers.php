<?php

declare(strict_types=1);

namespace Contenir\Mail;

use ArrayIterator;
use Contenir\Mail\Header\HeaderBlock;
use Contenir\Mail\Header\HeaderInterface;
use Contenir\Mail\Header\HeaderLocator;
use Contenir\Mail\Header\HeaderLocatorInterface;
use Contenir\Mail\Header\HeaderParser;
use Countable;
use IteratorAggregate;
use NoDiscard;
use Override;
use WeakMap;

use function array_filter;
use function array_is_list;
use function array_map;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function str_replace;
use function strcasecmp;
use function strstr;
use function strtolower;

/**
 * An ordered, immutable set of message headers.
 *
 * Names are matched case-insensitively and ignoring "-", "_", "." and spaces,
 * so "Content-Type" and "content_type" name the same header.
 *
 * Headers parsed by fromString() keep the text they were written with, and
 * toString() writes that text back unchanged, folding and encoding
 * included, for as long as the header itself is kept. A header replaced by
 * with() is written from its value. This keeps forwarded and DKIM-signed
 * headers byte for byte.
 *
 * @mago-expect lint:too-many-methods A collection: construction, with/without, lookup, output and iteration.
 * @implements IteratorAggregate<int, HeaderInterface>
 *
 * @mago-expect lint:cyclomatic-complexity A collection: lookup, derivation, writing and checked serialization.
 * @mago-expect lint:kan-defect A collection: lookup, derivation, writing and checked serialization.
 */
final readonly class Headers implements Countable, IteratorAggregate
{
    /** End of line for header fields */
    public const string EOL = "\r\n";

    /** Start of a continuation line when folding */
    public const string FOLDING = "\r\n ";

    /** @var list<HeaderInterface> */
    private array $headers;

    /**
     * The text each parsed header was written with, CRLF-folded.
     *
     * Filled only by the factory methods, before the new instance is returned.
     *
     * @var WeakMap<HeaderInterface, string|null>
     */
    private WeakMap $wireText;

    public function __construct(HeaderInterface ...$headers)
    {
        $this->headers  = array_values($headers);
        $this->wireText = new WeakMap();
    }

    /**
     * Parse a header block, as found at the start of a message or MIME part.
     *
     * Each header is parsed by the class the locator names for it. A header
     * that class rejects is kept as a GenericHeader, and a line that is not a
     * header at all is dropped, so one malformed header does not make the
     * whole message unreadable.
     *
     * @throws Exception\RuntimeException When a name is longer than HeaderName::MAX_LENGTH, or the block is too large.
     */
    public static function fromString(
        string $string,
        string $eol = self::EOL,
        HeaderLocatorInterface $locator = new HeaderLocator(),
    ): self {
        /** @var WeakMap<HeaderInterface, string|null> $wireText */
        $wireText = new WeakMap();
        $headers  = [];
        foreach ((new HeaderParser($locator))->parseBlock($string, $eol) as [$header, $text]) {
            $headers[]         = $header;
            $wireText[$header] = $text;
        }

        return self::build($headers, $wireText);
    }

    /**
     * Build headers from header objects, complete lines, `[name, value]` pairs or `name => value` entries.
     *
     * @param iterable<int|string, HeaderInterface|string|array{string, string}> $headers
     * @throws Header\Exception\InvalidArgumentException When a line is malformed or a name is longer than HeaderName::MAX_LENGTH.
     */
    public static function fromIterable(iterable $headers, HeaderLocatorInterface $locator = new HeaderLocator()): self
    {
        return new self(...(new HeaderParser($locator))->parseIterable($headers));
    }

    /**
     * Set a header, replacing any headers of the same name.
     *
     * The new header takes the position of the first one it replaces, or goes last.
     */
    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function with(HeaderInterface $header): self
    {
        $key      = self::normalise($header->getFieldName());
        $headers  = [];
        $replaced = false;
        foreach ($this->headers as $existing) {
            if (self::normalise($existing->getFieldName()) !== $key) {
                $headers[] = $existing;
                continue;
            }

            if (! $replaced) {
                $headers[] = $header;
                $replaced  = true;
            }
        }

        if (! $replaced) {
            $headers[] = $header;
        }

        return $this->derive($headers);
    }

    /**
     * Add a header after the existing ones, keeping any of the same name, as for Received.
     */
    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function withAdded(HeaderInterface $header): self
    {
        return $this->derive([...$this->headers, $header]);
    }

    /**
     * Add a header before the existing ones, keeping any of the same name, as for DKIM-Signature.
     */
    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function withFirst(HeaderInterface $header): self
    {
        return $this->derive([$header, ...$this->headers]);
    }

    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function without(string $name): self
    {
        $key = self::normalise($name);

        return $this->derive(array_values(array_filter(
            $this->headers,
            static fn(HeaderInterface $header): bool => self::normalise($header->getFieldName()) !== $key,
        )));
    }

    /**
     * The first header with this name.
     */
    public function get(string $name): ?HeaderInterface
    {
        return $this->all($name)[0] ?? null;
    }

    /**
     * Every header with this name, in order.
     *
     * @return list<HeaderInterface>
     */
    public function all(string $name): array
    {
        $key = self::normalise($name);

        return array_values(array_filter(
            $this->headers,
            static fn(HeaderInterface $header): bool => self::normalise($header->getFieldName()) === $key,
        ));
    }

    public function has(string $name): bool
    {
        return null !== $this->get($name);
    }

    /**
     * The header block, one line per header, each ending with a CRLF.
     *
     * A parsed header is written with the text it was read with.
     */
    public function toString(): string
    {
        $result = '';
        foreach ($this->headers as $header) {
            $line = $this->wireText[$header] ?? $header->toString();
            if ('' !== $line) {
                $result .= $line . self::EOL;
            }
        }

        return $result;
    }

    /**
     * Decoded values by header name; a name that appears more than once maps to a list.
     *
     * @return array<string, string|list<string>>
     */
    public function toArray(): array
    {
        /** @var array<string, list<string>> $values */
        $values = [];
        foreach ($this->headers as $header) {
            $values[$header->getFieldName()][] = $header->getFieldValue();
        }

        return array_map(
            /**
             * @param list<string> $list
             * @return string|list<string>
             */
            static fn(array $list): string|array => 1 === count($list) ? $list[0] : $list,
            $values,
        );
    }

    /**
     * @return list<HeaderInterface>
     */
    public function toList(): array
    {
        return $this->headers;
    }

    #[Override]
    public function count(): int
    {
        return count($this->headers);
    }

    /**
     * @return ArrayIterator<int, HeaderInterface>
     */
    #[Override]
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->headers);
    }

    /**
     * The headers, with the text each was read with, so a parsed message can be queued and still be written back as it was read.
     *
     * @return array{headers: list<HeaderInterface>, wireText: list<string|null>}
     */
    public function __serialize(): array
    {
        return [
            'headers'  => $this->headers,
            'wireText' => array_map(
                fn(HeaderInterface $header): ?string => $this->wireText[$header] ?? null,
                $this->headers,
            ),
        ];
    }

    /**
     * Restore serialized headers. Written text is kept only when it is one
     * well-formed header line for the same header, so serialized data cannot
     * put text into a message that parsing would not have kept.
     *
     * @param array<array-key, mixed> $data
     * @throws Exception\InvalidArgumentException When the data does not hold a list of headers.
     *
     * @mago-expect analysis:invalid-property-write PHP lets __unserialize() initialise readonly properties once.
     * @mago-expect analysis:mixed-assignment Serialized data is untyped until it is checked here.
     */
    public function __unserialize(array $data): void
    {
        $headers = $data['headers'] ?? null;
        $texts   = $data['wireText'] ?? null;
        if (! is_array($headers) || ! array_is_list($headers) || ! is_array($texts)) {
            throw new Exception\InvalidArgumentException('Serialized headers must hold a list of headers');
        }

        /** @var WeakMap<HeaderInterface, string|null> $wireText */
        $wireText = new WeakMap();
        foreach ($headers as $index => $header) {
            if (! $header instanceof HeaderInterface) {
                throw new Exception\InvalidArgumentException('Serialized headers must hold a list of headers');
            }

            $text              = $texts[$index] ?? null;
            $wireText[$header] = is_string($text) && self::isWireTextOf($header, $text) ? $text : null;
        }

        $this->headers  = $headers;
        $this->wireText = $wireText;
    }

    /**
     * Whether the text is a single well-formed header line, folded with CRLF, for the header.
     */
    private static function isWireTextOf(HeaderInterface $header, string $text): bool
    {
        try {
            $fields = HeaderBlock::fields($text, self::EOL);
        } catch (Exception\RuntimeException) {
            return false;
        }

        return (
            1 === count($fields)
                && $text === ($fields[0][1] ?? null)
                && 0 === strcasecmp((string) strstr($text, needle: ':', before_needle: true), $header->getFieldName())
        );
    }

    /**
     * A new collection of these headers, keeping the written text of those that came from this one.
     *
     * @param list<HeaderInterface> $headers
     */
    private function derive(array $headers): self
    {
        return self::build($headers, $this->wireText);
    }

    /**
     * @param list<HeaderInterface> $headers
     * @param WeakMap<HeaderInterface, string|null> $wireText
     */
    private static function build(array $headers, WeakMap $wireText): self
    {
        $built = new self(...$headers);
        foreach ($headers as $header) {
            $built->wireText[$header] = $wireText[$header] ?? null;
        }

        return $built;
    }

    private static function normalise(string $name): string
    {
        return str_replace(
            search: ['-', '_', ' ', '.'],
            replace: '',
            subject: strtolower($name),
        );
    }
}
