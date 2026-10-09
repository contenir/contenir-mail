<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Tnef;

use Contenir\Mail\Storage\Exception\RuntimeException;

use function crc32;
use function ord;
use function sprintf;
use function str_repeat;
use function strlen;
use function strrev;
use function strspn;
use function substr;

/**
 * Decompresses RTF stored in PR_RTF_COMPRESSED, as MS-OXRTFCP describes.
 *
 * The input is hostile, so decompression stops with an exception when the
 * output would grow past the size the header declares, when the header
 * declares more than the caller allows, when a reference points at
 * dictionary bytes not yet written, when the CRC does not match, and when
 * the data ends before its end marker.
 *
 * @internal Used by the TNEF reader.
 */
final class CompressedRtf
{
    /** The compression type of LZFu-compressed RTF, "LZFu" */
    public const int COMPRESSED = 0x7546_5A4C;

    /** The compression type of RTF stored as it is, "MELA" */
    public const int UNCOMPRESSED = 0x414C_454D;

    /** The text the dictionary starts with, from MS-OXRTFCP section 3.1.3.1 */
    public const string PREBUFFER =
        '{\rtf1\ansi\mac\deff0\deftab720{\fonttbl;}{\f0\fnil \froman \fswiss \fmodern '
            . '\fscript \fdecor MS Sans SerifSymbolArialTimes New RomanCourier{\colortbl\red0\green0\blue0'
            . "\r\n"
            . '\par \pard\plain\f0\fs20\b\i\u\tab\tx';

    /** Bytes of the header: the compressed size, the raw size, the compression type and the CRC */
    private const int HEADER_SIZE = 16;

    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

    /**
     * The RTF held by a PR_RTF_COMPRESSED value.
     *
     * @param int $maxBytes The most bytes of RTF the caller accepts.
     * @throws RuntimeException When the data is malformed or the RTF would be larger than $maxBytes.
     */
    public static function decompress(string $data, int $maxBytes): string
    {
        $header         = new ByteReader($data);
        $compressedSize = $header->uint32();
        $rawSize        = $header->uint32();
        $type           = $header->uint32();
        $crc            = $header->uint32();
        if ($compressedSize < (self::HEADER_SIZE - 4) || $compressedSize > (strlen($data) - 4)) {
            throw new RuntimeException(sprintf(
                'The compressed RTF claims %d bytes, but %d follow its size',
                $compressedSize,
                strlen($data) - 4,
            ));
        }

        if ($rawSize > $maxBytes) {
            throw new RuntimeException(sprintf(
                'The compressed RTF holds %d bytes, more than the %d allowed',
                $rawSize,
                $maxBytes,
            ));
        }

        $payload = substr($data, self::HEADER_SIZE, $compressedSize - (self::HEADER_SIZE - 4));

        return match ($type) {
            self::COMPRESSED => self::inflate($payload, $rawSize, $crc),
            self::UNCOMPRESSED => self::stored($payload, $rawSize),
            default => throw new RuntimeException(sprintf('The RTF has the unknown compression type 0x%08X', $type)),
        };
    }

    /**
     * The CRC of MS-OXRTFCP section 3.1.3.2: CRC-32 started from 0 rather than 0xFFFFFFFF, without the final inversion.
     *
     * CRC-32 is linear, so starting from 0 differs from crc32() by the crc32() of as many zero bytes.
     */
    public static function crc(string $data): int
    {
        return (
            crc32($data)
                ^ crc32(str_repeat(
                    string: "\0",
                    times: strlen($data),
                ))
        );
    }

    /**
     * @throws RuntimeException When the RTF is shorter than its header says.
     */
    private static function stored(string $payload, int $rawSize): string
    {
        if (strlen($payload) < $rawSize) {
            throw new RuntimeException(sprintf(
                'The uncompressed RTF claims %d bytes, but %d follow its header',
                $rawSize,
                strlen($payload),
            ));
        }

        return substr($payload, offset: 0, length: $rawSize);
    }

    /**
     * Run the LZFu tokens: each control byte says, bit by bit from the lowest,
     * whether a literal byte or a two-byte dictionary reference follows.
     * Literal bytes in a row are written together.
     *
     * @throws RuntimeException When the data is malformed or the output would grow past $rawSize.
     */
    private static function inflate(string $payload, int $rawSize, int $crc): string
    {
        if (self::crc($payload) !== $crc) {
            throw new RuntimeException('The compressed RTF fails its CRC check');
        }

        $dictionary = new Dictionary(self::PREBUFFER, $rawSize);
        $at         = 0;
        while (true) {
            $flags = strrev(sprintf('%08b', ord(self::take($payload, $at, 1))));
            $bit   = 0;
            while ($bit < 8) {
                $literals = strspn($flags, characters: '0', offset: $bit);
                if ($literals > 0) {
                    $dictionary->append(self::take($payload, $at, $literals, $dictionary));
                    $bit += $literals;
                    continue;
                }

                $reference = self::take($payload, $at, 2);
                $reference = (ord($reference[0]) << 8) | ord($reference[1]);
                if ($dictionary->copy($reference >> 4, ($reference & 0xF) + 2)) {
                    return $dictionary->output();
                }

                $bit++;
            }
        }
    }

    /**
     * The next $count bytes, moving $at past them.
     *
     * When the data ends early, the bytes there are are still written to
     * $dictionary first, so a literal run that grows past the limit is
     * refused for that, as it would be byte by byte.
     *
     * @throws RuntimeException When the data ends before its end marker, or the output would grow past its limit.
     */
    private static function take(string $payload, int &$at, int $count, ?Dictionary $dictionary = null): string
    {
        $bytes = substr($payload, $at, $count);
        if (strlen($bytes) < $count) {
            $dictionary?->append($bytes);
            throw new RuntimeException('The compressed RTF ends before its end marker');
        }

        $at += $count;

        return $bytes;
    }
}
