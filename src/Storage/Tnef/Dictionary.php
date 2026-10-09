<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Tnef;

use Contenir\Mail\Storage\Exception\RuntimeException;

use function sprintf;
use function str_pad;
use function strlen;
use function substr;

/**
 * The LZFu sliding dictionary, and the output written through it.
 *
 * The dictionary is a ring of SIZE bytes that starts with the prebuffer;
 * each byte of output is also written at the write position, which then
 * moves on, wrapping to the start. Rather than keep the ring, this keeps
 * the prebuffer followed by the output: the ring's byte at an offset is
 * the last byte of that text whose position is the offset modulo SIZE.
 * Until the ring first wraps, the bytes after the write position have
 * never been written, so a reference to them points before the start of
 * the data and is refused.
 *
 * @internal Used by CompressedRtf.
 */
final class Dictionary
{
    /** Bytes in the ring that references point into */
    public const int SIZE = 4096;

    private string $text;

    private readonly int $outputStart;

    /**
     * @param string $prebuffer The text the dictionary starts with, shorter than SIZE.
     * @param int $maxBytes The most bytes of output allowed.
     */
    public function __construct(
        string $prebuffer,
        private readonly int $maxBytes,
    ) {
        $this->text        = $prebuffer;
        $this->outputStart = strlen($prebuffer);
    }

    /**
     * Write bytes to the output and the dictionary.
     *
     * @throws RuntimeException When the output would grow past its limit.
     */
    public function append(string $bytes): void
    {
        if ((strlen($this->text) - $this->outputStart + strlen($bytes)) > $this->maxBytes) {
            throw new RuntimeException(sprintf(
                'The compressed RTF grows past the %d bytes its header declares',
                $this->maxBytes,
            ));
        }

        $this->text .= $bytes;
    }

    /**
     * Copy $length bytes from $offset, which may overlap the bytes being written.
     *
     * A copy that overlaps the bytes it writes reads them back as it goes,
     * so it repeats the bytes it starts with, which are already written.
     *
     * @return bool True when the reference is the end marker, which points at the write position.
     * @throws RuntimeException When the reference points at bytes never written,
     *     or the output would grow past its limit.
     */
    public function copy(int $offset, int $length): bool
    {
        $written  = strlen($this->text);
        $position = $written % self::SIZE;
        if ($written < self::SIZE && $offset > $position) {
            throw new RuntimeException(sprintf(
                'The compressed RTF refers to offset %d, before the start of its data',
                $offset,
            ));
        }

        if ($offset === $position) {
            return true;
        }

        $from  = $written - (($position - $offset + self::SIZE) % self::SIZE);
        $bytes = substr($this->text, $from, $length);
        $this->append(str_pad($bytes, $length, $bytes));

        return false;
    }

    public function output(): string
    {
        return substr($this->text, $this->outputStart);
    }
}
