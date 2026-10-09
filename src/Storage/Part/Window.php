<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Part;

use Generator;

use function max;
use function strlen;
use function strpos;
use function substr;

/**
 * A moving view of a content's blocks, for searching it without reading it whole.
 *
 * Offsets are counted from the start of the content. Bytes before the
 * last search position, less one, are dropped as the search moves on, so
 * memory stays bounded by about a block and a piece.
 *
 * @internal Used by MultipartSplitter.
 */
final class Window
{
    private string $buffer = '';

    /** The offset of the first byte of the buffer */
    private int $base = 0;

    /**
     * @param Generator<int, string> $blocks
     */
    public function __construct(
        private readonly Generator $blocks,
    ) {}

    /**
     * The offset of the first $needle at or after $from, or null when there is none.
     *
     * $from is 0 or an offset this window has read up to. Bytes before
     * $from - 1 may no longer be read afterwards.
     */
    public function find(string $needle, int $from): ?int
    {
        while (true) {
            $this->forget($from);
            $found = strpos($this->buffer, $needle, $from - $this->base);
            if (false !== $found) {
                return $this->base + $found;
            }

            $from = $this->resume($from, $needle);
            if (! $this->fill()) {
                return null;
            }
        }
    }

    /**
     * The piece of a line starting at $at, as Lines::of() would read it:
     * up to and including the next "\n", and at most Content::CHUNK bytes.
     */
    public function piece(int $at): string
    {
        $more = true;
        while ($more && ($this->base + strlen($this->buffer)) < ($at + Content::CHUNK)) {
            $more = $this->fill();
        }

        $piece = substr($this->buffer, $at - $this->base, Content::CHUNK);
        $break = strpos($piece, needle: "\n");

        return false === $break ? $piece : substr($piece, offset: 0, length: $break + 1);
    }

    /**
     * The byte at $at, which must not have been dropped.
     */
    public function byte(int $at): string
    {
        return $this->buffer[$at - $this->base];
    }

    /**
     * Drop the bytes before the one before $from, which no later read reaches.
     *
     * This only bounds memory: keeping more bytes reads the same.
     */
    private function forget(int $from): void
    {
        $count = $from - 1 - $this->base;
        if ($count > 0) {
            $this->buffer = substr($this->buffer, $count);
            $this->base   += $count;
        }
    }

    /**
     * Where a needle not found in the buffer may still start, once more bytes
     * are read: in its last strlen($needle) - 1 bytes, and not before $from.
     *
     * This only saves searching again: starting earlier finds nothing more.
     */
    private function resume(int $from, string $needle): int
    {
        return max($from, $this->base + strlen($this->buffer) - strlen($needle) + 1);
    }

    private function fill(): bool
    {
        if (! $this->blocks->valid()) {
            return false;
        }

        $this->buffer .= $this->blocks->current();
        $this->blocks->next();

        return true;
    }
}
