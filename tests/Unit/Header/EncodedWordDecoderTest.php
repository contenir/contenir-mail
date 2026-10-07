<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\EncodedWordDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EncodedWordDecoder::class)]
#[Group('unit')]
final class EncodedWordDecoderTest extends TestCase
{
    #[Test]
    #[DataProvider('convertedProvider')]
    public function convertsTextInCharsetsMailIsWrittenIn(string $bytes, string $charset, string $expected): void
    {
        static::assertSame($expected, EncodedWordDecoder::toUtf8($bytes, $charset));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function convertedProvider(): array
    {
        return [
            'Latin-1'                 => ["caf\xE9", 'ISO-8859-1', 'café'],
            'charset name lower case' => ["caf\xE9", 'iso-8859-1', 'café'],
            'Windows-1252 quotes'     => ["\x93hi\x94", 'windows-1252', '“hi”'],
            'KOI8-R'                  => ["\xF0\xD2\xC9\xD7\xC5\xD4", 'KOI8-R', 'Привет'],
            'Shift_JIS'               => ["\x93\xfa\x96\x7b", 'Shift_JIS', '日本'],
        ];
    }

    /**
     * A message chooses the charset, so only charsets mail is written in reach
     * iconv; glibc's ISO-2022-CN-EXT converter could overflow (CVE-2024-2961).
     */
    #[Test]
    #[DataProvider('unconvertedProvider')]
    public function leavesTextInOtherCharsetsAsItIsAgainstConverterAbuse(string $charset): void
    {
        static::assertSame("\x1B\$+I\x21\x22", EncodedWordDecoder::toUtf8("\x1B\$+I\x21\x22", $charset));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unconvertedProvider(): array
    {
        return [
            'ISO-2022-CN-EXT' => ['ISO-2022-CN-EXT'],
            'ISO-2022-CN'     => ['ISO-2022-CN'],
            'UTF-7'           => ['UTF-7'],
            'unknown'         => ['X-NOT-A-CHARSET'],
            'UTF-8'           => ['UTF-8'],
        ];
    }

    #[Test]
    public function leavesTextWithoutCharsetAsItIs(): void
    {
        static::assertSame("caf\xE9", EncodedWordDecoder::toUtf8("caf\xE9", null));
    }

    #[Test]
    public function decodesEncodedWordInAllowedCharset(): void
    {
        static::assertSame('café', EncodedWordDecoder::decode('=?ISO-8859-1?Q?caf=E9?='));
    }

    #[Test]
    public function leavesTextIconvCannotConvertAsItIsWithoutAWarning(): void
    {
        static::assertSame("\x93", EncodedWordDecoder::toUtf8("\x93", 'Shift_JIS'));
    }

    #[Test]
    public function leavesEmptyTextEmpty(): void
    {
        static::assertSame('', EncodedWordDecoder::toUtf8('', 'ISO-8859-1'));
    }
}
