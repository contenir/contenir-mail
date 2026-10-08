<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Tnef;

use Contenir\Mail\Storage\Exception\RuntimeException;

use function sprintf;
use function strlen;
use function substr;
use function unpack;

/**
 * Reads little-endian integers and byte runs from untrusted bytes, refusing any read past the end.
 *
 * @internal Used by the TNEF reader.
 */
final class ByteReader
{
    private int $offset = 0;

    public function __construct(
        private readonly string $bytes,
    ) {}

    public function remaining(): int
    {
        return strlen($this->bytes) - $this->offset;
    }

    /**
     * @throws RuntimeException When the data ends first.
     */
    public function uint8(): int
    {
        return self::unsigned('C', $this->bytes(1));
    }

    /**
     * @throws RuntimeException When the data ends first.
     */
    public function uint16(): int
    {
        return self::unsigned('v', $this->bytes(2));
    }

    /**
     * @throws RuntimeException When the data ends first.
     */
    public function uint32(): int
    {
        return self::unsigned('V', $this->bytes(4));
    }

    /**
     * The next $length bytes, checked against the bytes remaining before any are copied.
     *
     * @throws RuntimeException When the data ends first.
     */
    public function bytes(int $length): string
    {
        if ($length > $this->remaining()) {
            throw new RuntimeException(sprintf(
                'The TNEF data ends early: %d bytes are needed at offset %d, but %d remain',
                $length,
                $this->offset,
                $this->remaining(),
            ));
        }

        $bytes        = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }

    /**
     * @param 'C'|'v'|'V' $format
     */
    private static function unsigned(string $format, string $bytes): int
    {
        /** @var array{1: int} $value */
        $value = unpack($format, $bytes);

        return $value[1];
    }
}
