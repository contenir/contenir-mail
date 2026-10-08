<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

use function array_chunk;
use function implode;
use function is_string;
use function ord;
use function pack;
use function str_split;
use function strlen;

/**
 * Writes PR_RTF_COMPRESSED values (MS-OXRTFCP) for tests, from tokens rather than a compressor,
 * so each test says exactly which literals and references the decompressor meets.
 *
 * A token is a string, written as literal bytes, or an [offset, length] dictionary reference.
 */
final class RtfBuilder
{
    public const int LZFU = 0x7546_5A4C;

    public const int MELA = 0x414C_454D;

    /** The length of the prebuffer, so where the first byte of output goes in the dictionary */
    public const int PREBUFFER_LENGTH = 207;

    /**
     * LZFu-compressed RTF, ending with the end marker: a reference to the write position.
     *
     * @param list<string|array{int, int}> $tokens
     */
    public static function compressed(array $tokens, ?int $rawSize = null, ?int $crc = null): string
    {
        $produced = self::produced($tokens);
        $payload  = self::payload([...$tokens, [(self::PREBUFFER_LENGTH + $produced) % 4096, 2]]);

        return self::header($payload, $rawSize ?? $produced, self::LZFU, $crc ?? self::crc($payload)) . $payload;
    }

    /**
     * LZFu-compressed RTF that stops without its end marker.
     *
     * @param list<string|array{int, int}> $tokens
     */
    public static function unterminated(array $tokens): string
    {
        $payload = self::payload($tokens);

        return self::header($payload, self::produced($tokens), self::LZFU, self::crc($payload)) . $payload;
    }

    /**
     * RTF stored without compression ("MELA").
     */
    public static function stored(string $rtf, ?int $rawSize = null): string
    {
        return self::header($rtf, $rawSize ?? strlen($rtf), self::MELA, 0) . $rtf;
    }

    /**
     * The header: the size of what follows the size field, the raw size, the compression type and the CRC.
     */
    public static function header(string $payload, int $rawSize, int $type, int $crc): string
    {
        return pack('VVVV', strlen($payload) + 12, $rawSize, $type, $crc);
    }

    /**
     * The MS-OXRTFCP CRC, computed bit by bit as the specification describes it.
     */
    public static function crc(string $data): int
    {
        $crc = 0;
        foreach (str_split($data) as $character) {
            $crc ^= ord($character);
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = 0 === ($crc & 1) ? $crc >> 1 : ($crc >> 1) ^ 0xEDB8_8320;
            }
        }

        return $crc;
    }

    /**
     * @param list<string|array{int, int}> $tokens
     */
    private static function produced(array $tokens): int
    {
        $produced = 0;
        foreach ($tokens as $token) {
            $produced += is_string($token) ? strlen($token) : $token[1];
        }

        return $produced;
    }

    /**
     * Control bytes, each followed by the eight tokens its bits describe, the lowest bit first.
     *
     * @param list<string|array{int, int}> $tokens
     */
    private static function payload(array $tokens): string
    {
        $flags   = [];
        $encoded = [];
        foreach ($tokens as $token) {
            if (is_string($token)) {
                foreach (str_split($token) as $byte) {
                    $flags[]   = 0;
                    $encoded[] = $byte;
                }

                continue;
            }

            $flags[]   = 1;
            $encoded[] = pack('n', ($token[0] << 4) | ($token[1] - 2));
        }

        $payload = '';
        $chunks  = array_chunk($encoded, length: 8);
        foreach (array_chunk($flags, length: 8) as $index => $chunk) {
            $control = 0;
            foreach ($chunk as $bit => $flag) {
                $control |= $flag << $bit;
            }

            $payload .= pack('C', $control) . implode('', $chunks[$index]);
        }

        return $payload;
    }
}
