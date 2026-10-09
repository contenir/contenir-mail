<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Part;

use Closure;
use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\FileSystem;
use Generator;

use function fclose;
use function fopen;
use function fread;
use function fseek;
use function fwrite;
use function is_resource;
use function max;
use function min;
use function preg_replace;
use function strlen;

/**
 * Bytes of a stored message or part: a range of a stream or file, read only when asked for.
 *
 * Mbox and Maildir messages are ranges of their files, so a huge message
 * is never read whole just to list it or to reach one of its parts. A
 * Maildir file is opened only while it is being read, so holding many
 * messages holds no open files. Text from a server, or from a string, goes
 * into a php://temp stream, which keeps up to 2 MB in memory and the rest
 * on disk. A remote body is fetched by a loader the first time it is needed.
 *
 * @mago-expect lint:too-many-methods One constructor per source, and reading as a whole, in blocks and in slices.
 * @mago-expect lint:kan-defect Three sources, a shared stream, a file and a loader, read the same ways.
 *
 * @internal Used by Storage\Part.
 */
final class Content
{
    /** Most bytes in one piece from Lines::of() */
    public const int CHUNK = 8192;

    /** Most bytes read from the stream at a time */
    public const int BLOCK = 65_536;

    /** @var resource|null */
    private mixed $stream = null;

    /** @var (Closure(): string)|null */
    private ?Closure $loader;

    private ?string $path = null;

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
     * A range of a file, opened each time it is read and closed again, so holding it holds no open file.
     */
    public static function fromFile(string $path, int $start, int $end): self
    {
        $content        = new self(null, unquoteFrom: false);
        $content->path  = $path;
        $content->start = $start;
        $content->end   = max($start, $end);

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
        if (null === $this->path) {
            $this->load();
        }

        return $this->end - $this->start;
    }

    /**
     * @throws Exception\RuntimeException When the storage has been closed, or the file cannot be opened.
     */
    public function read(): string
    {
        $bytes = '';
        foreach ($this->chunks() as $chunk) {
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /**
     * The bytes from $from up to, not including, $to, both counted from the start of this content.
     *
     * @throws Exception\RuntimeException When the storage has been closed.
     */
    public function slice(int $from, int $to): self
    {
        if (null === $this->path) {
            $this->load();
        }

        $slice        = clone $this;
        $slice->start = min($this->start + $from, $this->end);
        $slice->end   = max($slice->start, min($this->start + $to, $this->end));

        return $slice;
    }

    /**
     * The bytes as stored, in blocks of at most BLOCK bytes, keyed by their offset from the start.
     *
     * The stream is sought before each block, so other ranges of a shared
     * stream may be read between blocks; a file is opened for the blocks and
     * closed after them. A stream that ends early, as a file cut short since
     * it was read, ends the blocks there.
     *
     * @return Generator<int, string>
     * @throws Exception\RuntimeException When the storage has been closed, or the file cannot be opened.
     */
    public function blocks(): Generator
    {
        $path   = $this->path;
        $stream = null === $path
            ? $this->load()
            : FileSystem::quietly(static fn(): mixed => fopen($path, mode: 'rb'));
        if (! is_resource($stream)) {
            throw new Exception\RuntimeException('Cannot open the message file; it may have been moved');
        }

        try {
            $offset = $this->start;
            while ($offset < $this->end) {
                fseek($stream, $offset);
                $block = (string) fread($stream, min(self::BLOCK, $this->end - $offset));
                if ('' === $block) {
                    return;
                }

                yield $offset - $this->start => $block;

                $offset += strlen($block);
            }
        } finally {
            if (null !== $path) {
                fclose($stream);
            }
        }
    }

    /**
     * The bytes as read() returns them, in blocks.
     *
     * Blocks of an mboxrd range end at line breaks, so the quoting of each
     * line is removed whole; other blocks are as blocks() reads them.
     *
     * @return Generator<int, string>
     * @throws Exception\RuntimeException When the storage has been closed, or the file cannot be opened.
     */
    public function chunks(): Generator
    {
        if (! $this->unquoteFrom) {
            foreach ($this->blocks() as $block) {
                yield $block;
            }

            return;
        }

        foreach (Lines::whole($this->blocks()) as $lines) {
            yield (string) preg_replace('/^>(>*From )/m', replacement: '$1', subject: $lines);
        }
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
