<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

use Contenir\Mail\Storage\Tnef\CompressedRtf;

use function is_string;
use function str_pad;
use function strlen;

/**
 * Decompresses RtfBuilder tokens byte by byte through a 4096-byte ring that starts
 * with the prebuffer, as MS-OXRTFCP describes it, to check the decompressor against.
 */
final class RtfRing
{
    /**
     * @param list<string|array{int, int}> $tokens
     */
    public static function decompress(array $tokens): string
    {
        $ring     = str_pad(CompressedRtf::PREBUFFER, length: 4096, pad_string: "\0");
        $position = RtfBuilder::PREBUFFER_LENGTH;
        $output   = '';
        foreach ($tokens as $token) {
            $length = is_string($token) ? strlen($token) : $token[1];
            for ($index = 0; $index < $length; $index++) {
                $byte            = is_string($token) ? $token[$index] : $ring[($token[0] + $index) % 4096];
                $ring[$position] = $byte;
                $position        = ($position + 1) % 4096;
                $output          .= $byte;
            }
        }

        return $output;
    }
}
