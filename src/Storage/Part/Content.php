<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Part;

use Closure;
use Contenir\Mail\Storage\Exception;
use Generator;

use function fgets;
use function fopen;
use function fseek;
use function fwrite;
use function is_resource;
use function max;
use function min;
use function preg_replace;
use function str_ends_with;
use function stream_get_contents;
use function strlen;

/**
 * Bytes of a stored message or part: a range of a stream, read only when asked for.
 *
 * Mbox and Maildir messages are ranges of their files, so a huge message
 * is never read whole just to list it or to reach one of its parts. Text
 * from a server, or from a string, goes into a php://temp stream, which
 * keeps up to 2 MB in memory and the rest on disk. A remote body is fetched
 * by a loader the first time it is needed.
 *
 * @internal Used by Storage\Part.
 */
final class Content
{
    /** Bytes read at a time when scanning lines */
    public const int CHUNK = 8192;

    /** @var resource|null */
    private mixed $stream = null;

    /** @var (Closure(): string)|null */
    private ?Closure $loader;

    private int $start = 0;

    private int $end = 0;

    /**
     * @param (Closure(): string)|null $loader
     */
    private function __construct(
        ?Closure $loader,
        private readonly bool $unquoteFrom,
    ) {
        $this->loader = $loader;
    }

    public static function fromString(string $bytes): self
    {
        return self::lazy(static fn(): string => $bytes);
    }

    /**
     * @param resource $stream A seekable stream, shared with other ranges and moved by every read.
     * @param bool $unquoteFrom Remove one ">" from lines matching ">*From " when reading, as for mboxrd.
     *
     * @mago-expect analysis:missing-parameter-type Streams have no native parameter type.
     */
    public static function fromStream($stream, int $start, int $end, bool $unquoteFrom = false): self
    {
        $content         = new self(null, $unquoteFrom);
        $content->stream = $stream;
        $content->start  = $start;
        $content->end    = max($start, $end);

        return $content;
    }

    /**
     * @param Closure(): string $loader Called once, the first time the bytes are needed.
     */
    public static function lazy(Closure $loader): self
    {
        return new self($loader, unquoteFrom: false);
    }

    /**
     * The number of bytes, as stored.
     *
     * @throws Exception\RuntimeException When the storage has been closed.
     */
    public function length(): int
    {
        $this->load();

        return $this->end - $this->start;
    }

    /**
     * @throws Exception\RuntimeException When the storage has been closed.
     */
    public function read(): string
    {
        $stream = $this->load();
        fseek($stream, $this->start);
        $bytes = (string) stream_get_contents($stream, $this->end - $this->start);

        return $this->unquoteFrom
            ? (string) preg_replace('/^>(>*From )/m', replacement: '$1', subject: $bytes)
            : $bytes;
    }

    /**
     * The bytes from $from up to, not including, $to, both counted from the start of this content.
     *
     * @throws Exception\RuntimeException When the storage has been closed.
     */
    public function slice(int $from, int $to): self
    {
        $stream = $this->load();

        return self::fromStream(
            $stream,
            min($this->start + $from, $this->end),
            min($this->start + $to, $this->end),
            $this->unquoteFrom,
        );
    }

    /**
     * The content in pieces of at most CHUNK bytes, each ending at a line
     * break or at the chunk size, keyed by their offset from the start.
     *
     * A piece starts a line when the piece before it ended with "\n". A
     * stream that ends early, as a file cut short since it was read, ends
     * the pieces there.
     *
     * @return Generator<int, string>
     * @throws Exception\RuntimeException When the storage has been closed.
     */
    public function lines(): Generator
    {
        $stream = $this->load();
        $offset = $this->start;
        while ($offset < $this->end) {
            fseek($stream, $offset);
            $line = (string) fgets($stream, min(self::CHUNK, $this->end - $offset) + 1);
            if ('' === $line) {
                return;
            }

            yield $offset - $this->start => $line;

            $offset += strlen($line);
        }
    }

    /**
     * Whether a piece from lines() ends a line.
     */
    public static function endsLine(string $piece): bool
    {
        return str_ends_with($piece, "\n");
    }

    /**
     * @return resource
     * @throws Exception\RuntimeException When the storage has been closed.
     */
    private function load(): mixed
    {
        if (null !== $this->loader) {
            $bytes        = ($this->loader)();
            $this->loader = null;
            $stream       = fopen('php://temp', mode: 'w+b');
            if (false === $stream) {
                // @codeCoverageIgnoreStart
                // Unreachable: php://temp is always available
                throw new Exception\RuntimeException('Cannot open a temporary stream');

                // @codeCoverageIgnoreEnd
            }

            fwrite($stream, $bytes);
            $this->stream = $stream;
            $this->end    = strlen($bytes);
        }

        if (! is_resource($this->stream)) {
            throw new Exception\RuntimeException('The storage this message was read from has been closed');
        }

        return $this->stream;
    }
}
