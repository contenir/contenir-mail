<?php

declare(strict_types=1);

namespace Contenir\Mail;

use ArrayIterator;
use Contenir\Mail\Header\HeaderInterface;
use Contenir\Mail\Header\HeaderLocator;
use Contenir\Mail\Header\HeaderLocatorInterface;
use Contenir\Mail\Header\HeaderParser;
use Countable;
use IteratorAggregate;
use Override;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function str_replace;
use function strtolower;

/**
 * An ordered, immutable set of message headers.
 *
 * Names are matched case-insensitively and ignoring "-", "_", "." and spaces,
 * so "Content-Type" and "content_type" name the same header.
 *
 * @mago-expect lint:too-many-methods A collection: construction, with/without, lookup, output and iteration.
 * @implements IteratorAggregate<int, HeaderInterface>
 */
final readonly class Headers implements Countable, IteratorAggregate
{
    /** End of line for header fields */
    public const string EOL = "\r\n";

    /** Start of a continuation line when folding */
    public const string FOLDING = "\r\n ";

    /** @var list<HeaderInterface> */
    private array $headers;

    public function __construct(HeaderInterface ...$headers)
    {
        $this->headers = array_values($headers);
    }

    /**
     * Parse a header block, as found at the start of a message or MIME part.
     *
     * Each header is parsed by the class the locator names for it. A header
     * that class rejects is kept as a GenericHeader, so one malformed header
     * does not make the whole message unreadable.
     *
     * @throws Exception\RuntimeException When the block is not a sequence of header lines.
     */
    public static function fromString(
        string $string,
        string $eol = self::EOL,
        HeaderLocatorInterface $locator = new HeaderLocator(),
    ): self {
        return new self(...(new HeaderParser($locator))->parseBlock($string, $eol));
    }

    /**
     * Build headers from header objects, complete lines, `[name, value]` pairs or `name => value` entries.
     *
     * @param iterable<int|string, HeaderInterface|string|array{string, string}> $headers
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

        return new self(...$headers);
    }

    /**
     * Add a header after the existing ones, keeping any of the same name, as for Received.
     */
    public function withAdded(HeaderInterface $header): self
    {
        return new self(...[...$this->headers, $header]);
    }

    public function without(string $name): self
    {
        $key = self::normalise($name);

        return new self(...array_filter(
            $this->headers,
            static fn(HeaderInterface $header): bool => self::normalise($header->getFieldName()) !== $key,
        ));
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
     */
    public function toString(): string
    {
        $result = '';
        foreach ($this->headers as $header) {
            $line = $header->toString();
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

    private static function normalise(string $name): string
    {
        return str_replace(
            search: ['-', '_', ' ', '.'],
            replace: '',
            subject: strtolower($name),
        );
    }
}
