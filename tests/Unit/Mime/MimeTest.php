<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Mime\Decode;
use Contenir\Mail\Mime\Mime;
use Contenir\Mail\Mime\TransferEncoding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function base64_decode;
use function explode;
use function max;
use function microtime;
use function quoted_printable_decode;
use function str_repeat;
use function strlen;

#[CoversClass(Mime::class)]
#[Group('unit')]
final class MimeTest extends TestCase
{
    #[Test]
    public function findsUnprintableCharacters(): void
    {
        static::assertFalse(Mime::isPrintable("Test with special chars: \xE4\xF6\xFC"));
    }

    #[Test]
    public function acceptsPrintableCharacters(): void
    {
        static::assertTrue(Mime::isPrintable('Test without special chars'));
    }

    #[DataProvider('encodeProvider')]
    #[Test]
    public function appliesATransferEncoding(TransferEncoding $encoding, string $content, string $expected): void
    {
        static::assertSame($expected, Mime::encode($content, $encoding));
    }

    /**
     * @return array<string, array{TransferEncoding, string, string}>
     */
    public static function encodeProvider(): array
    {
        return [
            'base64'                 => [TransferEncoding::Base64, 'Hello', 'SGVsbG8='],
            'base64 wrapped with lf' => [
                TransferEncoding::Base64,
                str_repeat('a', times: 60),
                str_repeat('YWFh', times: 18) . "\n" . str_repeat('YWFh', times: 2),
            ],
            'quoted-printable'       => [TransferEncoding::QuotedPrintable, "a=b\xE4", 'a=3Db=E4'],
            'quoted-printable lf'    => [
                TransferEncoding::QuotedPrintable,
                str_repeat('a', times: 80),
                str_repeat('a', times: 72) . "=\n" . str_repeat('a', times: 8),
            ],
            '7bit'                   => [TransferEncoding::SevenBit, "a=b\n", "a=b\n"],
            '8bit'                   => [TransferEncoding::EightBit, "a=b\xE4", "a=b\xE4"],
            'binary'                 => [TransferEncoding::Binary, "\x00\xFF", "\x00\xFF"],
        ];
    }

    #[DataProvider('encodeWithLineEndProvider')]
    #[Test]
    public function appliesATransferEncodingWithAnotherLineEnd(
        TransferEncoding $encoding,
        string $content,
        string $expected,
    ): void {
        static::assertSame($expected, Mime::encode($content, $encoding, eol: "\r\n"));
    }

    /**
     * @return array<string, array{TransferEncoding, string, string}>
     */
    public static function encodeWithLineEndProvider(): array
    {
        return [
            'base64'           => [
                TransferEncoding::Base64,
                str_repeat('a', times: 60),
                str_repeat('YWFh', times: 18) . "\r\n" . str_repeat('YWFh', times: 2),
            ],
            'quoted-printable' => [
                TransferEncoding::QuotedPrintable,
                str_repeat('a', times: 80),
                str_repeat('a', times: 72) . "=\r\n" . str_repeat('a', times: 8),
            ],
        ];
    }

    #[Test]
    public function encodesNothingAsNothing(): void
    {
        static::assertSame('', Mime::encode('', TransferEncoding::Base64));
    }

    #[Test]
    public function encodesQuotedPrintableThatDecodesToTheOriginal(): void
    {
        $text =
            "This is a cool Test Text with special chars: \xE4\xF6\xFC\xDF\n"
            . "and with multiple lines\xE4\xF6\xFC\xDF some of the Lines are long, long"
            . ', long, long, long, long, long, long, long, long, long, long'
            . ', long, long, long, long, long, long, long, long, long, long'
            . ', long, long, long, long, long, long, long, long, long, long'
            . ", long, long, long, long and with \xE4\xF6\xFC\xDF";

        static::assertSame($text, quoted_printable_decode(Mime::encodeQuotedPrintable($text)));
    }

    #[Test]
    public function encodesADotAtTheStartOfAQuotedPrintableLine(): void
    {
        $text = str_repeat('a', Mime::LINELENGTH) . '.bbb';

        static::assertSame(str_repeat('a', Mime::LINELENGTH) . "=\n=2Ebbb", Mime::encodeQuotedPrintable($text));
    }

    #[Test]
    public function encodesSpacesAndDotsAtQuotedPrintableLineEdges(): void
    {
        $text = str_repeat(' ', Mime::LINELENGTH) . str_repeat('.', Mime::LINELENGTH);

        static::assertSame(
            str_repeat(' ', Mime::LINELENGTH - 1) . "=20=\n=2E" . str_repeat('.', Mime::LINELENGTH - 1),
            Mime::encodeQuotedPrintable($text),
        );
    }

    #[Test]
    public function doesNotBreakAQuotedPrintableOctetAcrossLines(): void
    {
        $text = str_repeat('a', Mime::LINELENGTH - 2) . '=.bbb';

        static::assertSame(
            str_repeat('a', Mime::LINELENGTH - 2) . "=\n=3D.bbb",
            Mime::encodeQuotedPrintable($text),
        );
    }

    #[Test]
    public function encodesBase64ThatDecodesToTheOriginal(): void
    {
        $content = str_repeat("\x88\xAA\xAF\xBF\x29\x88\xAA\xAF\xBF\x29\x88\xAA\xAF", times: 4);

        static::assertSame($content, base64_decode(Mime::encodeBase64($content), strict: true));
    }

    #[Test]
    #[Group('Laminas-1058')]
    public function encodesTrailingWhitespaceWithoutLoopingForever(): void
    {
        $text = "my body\r\n\r\n...after two newlines\r\n ";

        static::assertStringContainsString(
            "my body\r\n\r\n...after two newlines",
            quoted_printable_decode(Mime::encodeQuotedPrintable($text)),
        );
    }

    #[DataProvider('quotedPrintableHeaderProvider')]
    #[Group('Laminas-1688')]
    #[Test]
    public function encodesAQuotedPrintableHeader(string $str, string $charset, string $result): void
    {
        static::assertSame($result, Mime::encodeQuotedPrintableHeader($str, $charset));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function quotedPrintableHeaderProvider(): array
    {
        // phpcs:disable Generic.Files.LineLength.TooLong
        return [
            'umlauts'                   => ['äöü', 'UTF-8', '=?UTF-8?Q?=C3=A4=C3=B6=C3=BC?='],
            'trailing space'            => ['äöü ', 'UTF-8', '=?UTF-8?Q?=C3=A4=C3=B6=C3=BC?='],
            'euro sign'                 => ['Gimme more €', 'UTF-8', '=?UTF-8?Q?Gimme=20more=20=E2=82=AC?='],
            'long sentence'             => [
                'Alle meine Entchen schwimmen in dem See, schwimmen in dem See, Köpfchen in das Wasser, Schwänzchen in die Höh!',
                'UTF-8',
                "=?UTF-8?Q?Alle=20meine=20Entchen=20schwimmen=20in=20dem=20See=2C=20?=\n =?UTF-8?Q?schwimmen=20in=20dem=20See=2C=20K=C3=B6pfchen=20in=20das=20?=\n =?UTF-8?Q?Wasser=2C=20Schw=C3=A4nzchen=20in=20die=20H=C3=B6h!?=",
            ],
            'long word'                 => [
                'ääääääääääääääääääääääääääääääääää',
                'UTF-8',
                '=?UTF-8?Q?=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4?=',
            ],
            'letter and digit'          => ['A0', 'UTF-8', '=?UTF-8?Q?A0?='],
            'long word then short word' => [
                'äääääääääääääää ä',
                'UTF-8',
                "=?UTF-8?Q?=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=20?=\n =?UTF-8?Q?=C3=A4?=",
            ],
            'two long words'            => [
                'äääääääääääääää äääääääääääääää',
                'UTF-8',
                "=?UTF-8?Q?=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=20?=\n =?UTF-8?Q?=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4?=",
            ],
            'short word then long word' => [
                'ä äääääääääääääää',
                'UTF-8',
                '=?UTF-8?Q?=C3=A4=20=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4?=',
            ],
        ];

        // phpcs:enable
    }

    #[DataProvider('quotedPrintableHeaderWithNameProvider')]
    #[Test]
    public function foldsAQuotedPrintableHeaderAllowingForTheHeaderName(
        string $str,
        string $charset,
        string $expectedResult,
        int $headerLength,
    ): void {
        static::assertSame(
            $expectedResult,
            Mime::encodeQuotedPrintableHeader($str, $charset, 78, Mime::LINEEND, $headerLength),
        );
    }

    /**
     * @return array<string, array{string, string, string, int}>
     */
    public static function quotedPrintableHeaderWithNameProvider(): array
    {
        return [
            'long string with header name size'     => [
                'xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx',
                'UTF-8',
                '=?UTF-8?Q?xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20?='
                    . Mime::LINEEND
                    . ' =?UTF-8?Q?xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx?=',
                9,
            ],
            'long string without header name size'  => [
                'xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx',
                'UTF-8',
                '=?UTF-8?Q?xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20?='
                    . Mime::LINEEND
                    . ' =?UTF-8?Q?xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx?=',
                0,
            ],
            'short string with header name size'    => [
                'xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx',
                'UTF-8',
                '=?UTF-8?Q?xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20?='
                    . Mime::LINEEND
                    . ' =?UTF-8?Q?xxxxx=20xxxxx=20xxxxx?=',
                11,
            ],
            'short string without header name size' => [
                'xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx',
                'UTF-8',
                '=?UTF-8?Q?xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx?=',
                11,
            ],
        ];
    }

    #[DataProvider('base64HeaderProvider')]
    #[Group('Laminas-1688')]
    #[Test]
    public function encodesABase64Header(string $str, string $charset, string $result): void
    {
        static::assertSame($result, Mime::encodeBase64Header($str, $charset));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function base64HeaderProvider(): array
    {
        // phpcs:disable Generic.Files.LineLength.TooLong
        return [
            'umlauts'       => ['äöü', 'UTF-8', '=?UTF-8?B?w6TDtsO8?='],
            'long sentence' => [
                'Alle meine Entchen schwimmen in dem See, schwimmen in dem See, Köpfchen in das Wasser, Schwänzchen in die Höh!',
                'UTF-8',
                "=?UTF-8?B?QWxsZSBtZWluZSBFbnRjaGVuIHNjaHdpbW1lbiBpbiBkZW0gU2VlLCBzY2h3?=\n =?UTF-8?B?aW1tZW4gaW4gZGVtIFNlZSwgS8O2cGZjaGVuIGluIGRhcyBXYXNzZXIsIFNj?=\n =?UTF-8?B?aHfDpG56Y2hlbiBpbiBkaWUgSMO2aCE=?=",
            ],
        ];

        // phpcs:enable
    }

    /**
     * Base64 groups are four characters long, so every line length rounds down to whole groups.
     */
    #[DataProvider('base64HeaderWrapProvider')]
    #[Test]
    public function wrapsABase64HeaderThatDecodesToTheOriginal(string $str, int $lineLength): void
    {
        static::assertSame(
            $str,
            Decode::decodeQuotedPrintable(Mime::encodeBase64Header($str, 'UTF-8', $lineLength)),
        );
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function base64HeaderWrapProvider(): array
    {
        $cases = [];
        $texts = [
            'umlauts'  => 'äöüäöüäöüäöüäöüäöüäöü',
            'sentence' => 'Alle meine Entchen schwimmen in dem See, schwimmen in dem See, '
                . 'Köpfchen in das Wasser, Schwänzchen in die Höh!',
        ];
        foreach ($texts as $name => $text) {
            foreach ([20, 21, 22, 23] as $lineLength) {
                $cases["{$name} in {$lineLength} characters"] = [$text, $lineLength];
            }
        }

        return $cases;
    }

    #[Test]
    public function fillsTheFirstQuotedPrintableHeaderLineToExactlyTheLineLength(): void
    {
        static::assertSame(
            '=?UTF-8?Q?' . str_repeat('x', times: 56) . '=20y?=',
            Mime::encodeQuotedPrintableHeader(str_repeat('x', times: 56) . ' y', 'UTF-8'),
        );
    }

    #[Test]
    public function foldsAQuotedPrintableHeaderWordThatWouldPassTheLineLength(): void
    {
        static::assertSame(
            '=?UTF-8?Q?' . str_repeat('x', times: 57) . '=20?=' . Mime::LINEEND . ' =?UTF-8?Q?y?=',
            Mime::encodeQuotedPrintableHeader(str_repeat('x', times: 57) . ' y', 'UTF-8'),
        );
    }

    #[DataProvider('headerLineLengthProvider')]
    #[Group('Laminas-1688')]
    #[Test]
    public function keepsQuotedPrintableHeaderLinesWithinTheLength(int $lineLength): void
    {
        $subject =
            'Alle meine Entchen schwimmen in dem See, schwimmen in dem See, '
            . 'Köpfchen in das Wasser, Schwänzchen in die Höh!';
        $lines = explode(Mime::LINEEND, Mime::encodeQuotedPrintableHeader($subject, 'UTF-8', $lineLength));

        static::assertLessThanOrEqual($lineLength, max(array_map(strlen(...), $lines)));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function headerLineLengthProvider(): array
    {
        return [
            '100 characters' => [100],
            '40 characters'  => [40],
        ];
    }

    #[DataProvider('charsetProvider')]
    #[Test]
    public function detectsTheCharsetOfAnEncodedWord(string $expected, string $string): void
    {
        static::assertSame($expected, Mime::mimeDetectCharset($string));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function charsetProvider(): array
    {
        return [
            'plain text'                 => ['ASCII', 'test'],
            'ascii encoded word'         => ['ASCII', '=?ASCII?Q?test?='],
            'utf-8 encoded word'         => ['UTF-8', '=?UTF-8?Q?test?='],
            'iso-8859-1 with text after' => [
                'ISO-8859-1',
                '=?ISO-8859-1?Q?Pr=FCfung_f=FCr?= Entwerfen von einer MIME kopfzeile',
            ],
            'utf-8 with umlauts'         => ['UTF-8', '=?UTF-8?Q?Pr=C3=BCfung=20Pr=C3=BCfung?='],
            'lower case charset'         => ['UTF-8', '=?utf-8?Q?test?='],
        ];
    }

    #[Group('slow')]
    #[Test]
    public function encodesLongQuotedPrintableInputQuickly(): void
    {
        $str  = str_repeat('this could be anything, ', times: 200_000);
        $time = microtime(true);
        Mime::encodeQuotedPrintable($str);

        static::assertLessThan(5, microtime(true) - $time);
    }
}
