<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Mime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function count;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function explode;
use function microtime;
use function quoted_printable_decode;
use function str_repeat;
use function strlen;

class MimeTest extends TestCase
{
    /**
     * Stores the original set timezone
     *
     * @var string
     */
    private $originalTimezone;

    /**
     * Setup environment
     */
    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();
    }

    /**
     * Tear down environment
     */
    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
    }

    #[Test]
    public function boundary()
    {
        // check boundary for uniqueness
        $m1 = new Mime\Mime();
        $m2 = new Mime\Mime();
        static::assertNotEquals($m1->boundary(), $m2->boundary());

        // check instantiating with arbitrary boundary string
        $myBoundary = 'mySpecificBoundary';
        $m3         = new Mime\Mime($myBoundary);
        static::assertSame($m3->boundary(), $myBoundary);
    }

    #[Test]
    public function isNotPrintable()
    {
        static::assertFalse(Mime\Mime::isPrintable('Test with special chars: �����'));
    }

    #[Test]
    public function isPrintable()
    {
        static::assertTrue(Mime\Mime::isPrintable('Test without special chars'));
    }

    #[Test]
    public function qP()
    {
        $text =
            "This is a cool Test Text with special chars: ����\n"
            . 'and with multiple lines���� some of the Lines are long, long'
            . ', long, long, long, long, long, long, long, long, long, long'
            . ', long, long, long, long, long, long, long, long, long, long'
            . ', long, long, long, long, long, long, long, long, long, long'
            . ', long, long, long, long and with ����';

        $qp = Mime\Mime::encodeQuotedPrintable($text);
        static::assertSame(quoted_printable_decode($qp), $text);
    }

    #[Test]
    public function quotedPrintableNoDotAtBeginningOfLine()
    {
        $text = str_repeat('a', Mime\Mime::LINELENGTH) . '.bbb';
        $qp   = Mime\Mime::encodeQuotedPrintable($text);

        $expected = str_repeat('a', Mime\Mime::LINELENGTH) . "=\n=2Ebbb";

        static::assertSame($expected, $qp);
    }

    #[Test]
    public function quotedPrintableSpacesAndDots()
    {
        $text = str_repeat(' ', Mime\Mime::LINELENGTH) . str_repeat('.', Mime\Mime::LINELENGTH);
        $qp   = Mime\Mime::encodeQuotedPrintable($text);

        $expected =
            str_repeat(' ', Mime\Mime::LINELENGTH - 1)
            . "=20=\n=2E"
            . str_repeat('.', Mime\Mime::LINELENGTH - 1);

        static::assertSame($expected, $qp);
    }

    #[Test]
    public function quotedPrintableDoesNotBreakOctets()
    {
        $text = str_repeat('a', Mime\Mime::LINELENGTH - 2) . '=.bbb';
        $qp   = Mime\Mime::encodeQuotedPrintable($text);

        $expected = str_repeat('a', Mime\Mime::LINELENGTH - 2) . "=\n=3D.bbb";

        static::assertSame($expected, $qp);
    }

    #[Test]
    public function base64()
    {
        $content = str_repeat("\x88\xAA\xAF\xBF\x29\x88\xAA\xAF\xBF\x29\x88\xAA\xAF", 4);
        $encoded = Mime\Mime::encodeBase64($content);
        static::assertSame($content, base64_decode($encoded));
    }

    #[Test]
    public function laminas1058WhitespaceAtEndOfBodyCausesInfiniteLoop()
    {
        $text   = "my body\r\n\r\n...after two newlines\r\n ";
        $result = quoted_printable_decode(Mime\Mime::encodeQuotedPrintable($text));
        static::assertStringContainsString("my body\r\n\r\n...after two newlines", $result, $result);
    }

    #[Test]
    #[Group('Laminas-1688')]
    #[DataProvider('dataTestEncodeMailHeaderQuotedPrintable')]
    public function encodeMailHeaderQuotedPrintable(string $str, string $charset, string $result): void
    {
        static::assertSame($result, Mime\Mime::encodeQuotedPrintableHeader($str, $charset));
    }

    /** @psalm-return array<array-key, array{0: string, 1: string, 2: string}> */
    public static function dataTestEncodeMailHeaderQuotedPrintable(): array
    {
        // phpcs:disable Generic.Files.LineLength.TooLong
        return [
            ['äöü', 'UTF-8', '=?UTF-8?Q?=C3=A4=C3=B6=C3=BC?='],
            ['äöü ', 'UTF-8', '=?UTF-8?Q?=C3=A4=C3=B6=C3=BC?='],
            ['Gimme more €', 'UTF-8', '=?UTF-8?Q?Gimme=20more=20=E2=82=AC?='],
            [
                'Alle meine Entchen schwimmen in dem See, schwimmen in dem See, Köpfchen in das Wasser, Schwänzchen in die Höh!',
                'UTF-8',
                "=?UTF-8?Q?Alle=20meine=20Entchen=20schwimmen=20in=20dem=20See=2C=20?=\n =?UTF-8?Q?schwimmen=20in=20dem=20See=2C=20K=C3=B6pfchen=20in=20das=20?=\n =?UTF-8?Q?Wasser=2C=20Schw=C3=A4nzchen=20in=20die=20H=C3=B6h!?=",
            ],
            [
                'ääääääääääääääääääääääääääääääääää',
                'UTF-8',
                '=?UTF-8?Q?=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4?=',
            ],
            ['A0', 'UTF-8', '=?UTF-8?Q?A0?='],
            [
                'äääääääääääääää ä',
                'UTF-8',
                "=?UTF-8?Q?=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=20?=\n =?UTF-8?Q?=C3=A4?=",
            ],
            [
                'äääääääääääääää äääääääääääääää',
                'UTF-8',
                "=?UTF-8?Q?=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=20?=\n =?UTF-8?Q?=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4?=",
            ],
            [
                'ä äääääääääääääää',
                'UTF-8',
                '=?UTF-8?Q?=C3=A4=20=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4=C3=A4?=',
            ],
        ];

        // phpcs:enable
    }

    #[Test]
    #[DataProvider('dataTestEncodeMailHeaderQuotedPrintableWithHeaderName')]
    public function encodeMailHeaderQuotedPrintableWithHeaderName(
        string $str,
        string $charset,
        string $expectedResult,
        int $headerLength,
    ): void {
        $actualResult = Mime\Mime::encodeQuotedPrintableHeader($str, $charset, 78, Mime\Mime::LINEEND, $headerLength);
        static::assertSame($expectedResult, $actualResult);
    }

    /** @psalm-return array<array-key, array{0: string, 1: string, 2: string, 3: int}> */
    public static function dataTestEncodeMailHeaderQuotedPrintableWithHeaderName(): array
    {
        return [
            'long string with header name size'     => [
                'xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx',
                'UTF-8',
                '=?UTF-8?Q?xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20?='
                    . Mime\Mime::LINEEND
                    . ' =?UTF-8?Q?xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx?=',
                9,
            ],
            'long string without header name size'  => [
                'xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx',
                'UTF-8',
                '=?UTF-8?Q?xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20?='
                    . Mime\Mime::LINEEND
                    . ' =?UTF-8?Q?xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx?=',
                0,
            ],
            'short string with header name size'    => [
                'xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx xxxxx',
                'UTF-8',
                '=?UTF-8?Q?xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20xxxxx=20?='
                    . Mime\Mime::LINEEND
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

    #[Test]
    #[Group('Laminas-1688')]
    #[DataProvider('dataTestEncodeMailHeaderBase64')]
    public function encodeMailHeaderBase64(string $str, string $charset, string $result): void
    {
        static::assertSame($result, Mime\Mime::encodeBase64Header($str, $charset));
    }

    /** @psalm-return array<array-key, array{0: string, 1: string, 2: string}> */
    public static function dataTestEncodeMailHeaderBase64(): array
    {
        // phpcs:disable Generic.Files.LineLength.TooLong
        return [
            ['äöü', 'UTF-8', '=?UTF-8?B?w6TDtsO8?='],
            [
                'Alle meine Entchen schwimmen in dem See, schwimmen in dem See, Köpfchen in das Wasser, Schwänzchen in die Höh!',
                'UTF-8',
                "=?UTF-8?B?QWxsZSBtZWluZSBFbnRjaGVuIHNjaHdpbW1lbiBpbiBkZW0gU2VlLCBzY2h3?=\n =?UTF-8?B?aW1tZW4gaW4gZGVtIFNlZSwgS8O2cGZjaGVuIGluIGRhcyBXYXNzZXIsIFNj?=\n =?UTF-8?B?aHfDpG56Y2hlbiBpbiBkaWUgSMO2aCE=?=",
            ],
        ];

        // phpcs:enable
    }

    /**
     * base64 chunk are 4 chars long
     * try to encode/decode with 4 line length
     */
    #[Test]
    #[DataProvider('dataTestEncodeMailHeaderBase64wrap')]
    public function encodeMailHeaderBase64Wrap(string $str): void
    {
        static::assertSame(
            $str,
            Mime\Decode::decodeQuotedPrintable(Mime\Mime::encodeBase64Header($str, 'UTF-8', 20)),
        );
        static::assertSame(
            $str,
            Mime\Decode::decodeQuotedPrintable(Mime\Mime::encodeBase64Header($str, 'UTF-8', 21)),
        );
        static::assertSame(
            $str,
            Mime\Decode::decodeQuotedPrintable(Mime\Mime::encodeBase64Header($str, 'UTF-8', 22)),
        );
        static::assertSame(
            $str,
            Mime\Decode::decodeQuotedPrintable(Mime\Mime::encodeBase64Header($str, 'UTF-8', 23)),
        );
    }

    /** @psalm-return array<array-key, array{0: string}> */
    public static function dataTestEncodeMailHeaderBase64wrap(): array
    {
        return [
            ['äöüäöüäöüäöüäöüäöüäöü'],
            [
                'Alle meine Entchen schwimmen in dem See, schwimmen in dem See, '
                    . 'Köpfchen in das Wasser, Schwänzchen in die Höh!',
            ],
        ];
    }

    #[Test]
    public function fromMessageMultiPart()
    {
        $message = Mime\Message::createFromMessage(
            '--089e0141a1902f83ee04e0a07b7a'
                . "\r\n"
                . 'Content-Type: multipart/alternative; boundary=089e0141a1902f83e904e0a07b78'
                . "\r\n"
                . "\r\n"
                . '--089e0141a1902f83e904e0a07b78'
                . "\r\n"
                . 'Content-Type: text/plain; charset=UTF-8'
                . "\r\n"
                . "\r\n"
                . 'Foo'
                . "\r\n"
                . "\r\n"
                . '--089e0141a1902f83e904e0a07b78'
                . "\r\n"
                . 'Content-Type: text/html; charset=UTF-8'
                . "\r\n"
                . "\r\n"
                . '<p>Foo</p>'
                . "\r\n"
                . "\r\n"
                . '--089e0141a1902f83e904e0a07b78--'
                . "\r\n"
                . '--089e0141a1902f83ee04e0a07b7a'
                . "\r\n"
                . 'Content-Type: image/png; name="1.png"'
                . "\r\n"
                . 'Content-Disposition: attachment; filename="1.png"'
                . "\r\n"
                . 'Content-Transfer-Encoding: base64'
                . "\r\n"
                . 'X-Attachment-Id: barquux'
                . "\r\n"
                . "\r\n"
                . 'Zm9vCg=='
                . "\r\n"
                . '--089e0141a1902f83ee04e0a07b7a--',
            '089e0141a1902f83ee04e0a07b7a',
        );
        static::assertSame(2, count($message->getParts()));
    }

    /** @psalm-return array<array-key, array{0: string, 1: string, 2: string}> */
    public static function dataTestFromMessageDecode(): array
    {
        // phpcs:disable Generic.Files.LineLength.TooLong
        return [
            ['äöü', 'quoted-printable', '=C3=A4=C3=B6=C3=BC'],
            [
                'Alle meine Entchen schwimmen in dem See, schwimmen in dem See, Köpfchen in das Wasser, Schwänzchen in die Höh!',
                'quoted-printable',
                'Alle meine Entchen schwimmen in dem See, schwimmen in dem See, K=C3=B6pfche=
n in das Wasser, Schw=C3=A4nzchen in die H=C3=B6h!',
            ],
            ['foobar', 'base64', 'Zm9vYmFyCg=='],
        ];

        // phpcs:enable
    }

    #[Test]
    #[DataProvider('dataTestFromMessageDecode')]
    public function fromMessageDecode(string $input, string $encoding, string $result): void
    {
        $parts = Mime\Message::createFromMessage(
            "--089e0141a1902f83ee04e0a07b7a\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: {$encoding}\r\n\r\n{$result}\r\n--089e0141a1902f83ee04e0a07b7a--",
            '089e0141a1902f83ee04e0a07b7a',
        )->getParts();
        static::assertSame("{$input}\n", $parts[0]->getRawContent());
    }

    #[Test]
    #[Group('Laminas-1688')]
    public function lineLengthInQuotedPrintableHeaderEncoding()
    {
        $subject =
            'Alle meine Entchen schwimmen in dem See, schwimmen in dem See, '
            . 'Köpfchen in das Wasser, Schwänzchen in die Höh!';
        $encoded = Mime\Mime::encodeQuotedPrintableHeader($subject, 'UTF-8', 100);
        foreach (explode(Mime\Mime::LINEEND, $encoded) as $line) {
            static::assertLessThanOrEqual(
                100,
                strlen($line),
                "Line '" . $line . "' is " . strlen($line) . ' chars long, only 100 allowed.',
            );
        }
        $encoded = Mime\Mime::encodeQuotedPrintableHeader($subject, 'UTF-8', 40);
        foreach (explode(Mime\Mime::LINEEND, $encoded) as $line) {
            static::assertLessThanOrEqual(
                40,
                strlen($line),
                "Line '" . $line . "' is " . strlen($line) . ' chars long, only 40 allowed.',
            );
        }
    }

    /** @psalm-return array<array-key, array{0: string, 1: string}> */
    public static function dataTestCharsetDetection(): array
    {
        return [
            ['ASCII',      'test'],
            ['ASCII',      '=?ASCII?Q?test?='],
            ['UTF-8',      '=?UTF-8?Q?test?='],
            ['ISO-8859-1', '=?ISO-8859-1?Q?Pr=FCfung_f=FCr?= Entwerfen von einer MIME kopfzeile'],
            ['UTF-8',      '=?UTF-8?Q?Pr=C3=BCfung=20Pr=C3=BCfung?='],
        ];
    }

    #[Test]
    #[DataProvider('dataTestCharsetDetection')]
    public function charsetDetection(string $expected, string $string): void
    {
        static::assertSame($expected, Mime\Mime::mimeDetectCharset($string));
    }

    #[Test]
    public function encodeQuotedPrintableShouldBeFastEnoughForLongInputStrings()
    {
        $str  = str_repeat('this could be anything, ', 200_000);
        $time = microtime(true);
        Mime\Mime::encodeQuotedPrintable($str);
        static::assertLessThan(5, microtime(true) - $time);
    }
}
