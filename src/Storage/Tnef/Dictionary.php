<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Tnef;

use Contenir\Mail\Storage\Exception\RuntimeException;

use function sprintf;
use function strlen;

/**
 * The LZFu sliding dictionary, and the output written through it.
 *
 * The dictionary is a ring: each byte of output is also written at the
 * write position, which then moves on, wrapping to the start. Until it
 * first wraps, the bytes after the write position have never been written,
 * so a reference to them points before the start of the data and is refused.
 *
 * @internal Used by CompressedRtf.
 */
final class Dictionary
{
    private string $output = '';

    private bool $wrapped = false;

    /**
     * @param string $ring The dictionary, already holding the prebuffer.
     * @param int $position Where the next byte is written: just after the prebuffer.
     * @param int $maxBytes The most bytes of output allowed.
     */
    public function __construct(
        private string $ring,
        private int $position,
        private readonly int $maxBytes,
    ) {}

    /**
     * @throws RuntimeException When the output would grow past its limit.
     */
    public function append(string $character): void
    {
        if (strlen($this->output) >= $this->maxBytes) {
            throw new RuntimeException(sprintf(
                'The compressed RTF grows past the %d bytes its header declares',
                $this->maxBytes,
            ));
        }

        $this->output                .= $character;
        $this->ring[$this->position] = $character;
        $this->position              = ($this->position + 1) % strlen($this->ring);
        $this->wrapped               = $this->wrapped || 0 === $this->position;
    }

    /**
     * Copy $length bytes from $offset, which may overlap the bytes being written.
     *
     * @return bool True when the reference is the end marker, which points at the write position.
     * @throws RuntimeException When the reference points at bytes never written,
     *     or the output would grow past its limit.
     */
    public function copy(int $offset, int $length): bool
    {
        if (! $this->wrapped && $offset > $this->position) {
            throw new RuntimeException(sprintf(
                'The compressed RTF refers to offset %d, before the start of its data',
                $offset,
            ));
        }

        if ($offset === $this->position) {
            return true;
        }

        for ($index = 0; $index < $length; $index++) {
            $this->append($this->ring[($offset + $index) % strlen($this->ring)]);
        }

        return false;
    }

    public function output(): string
    {
        return $this->output;
    }
}
