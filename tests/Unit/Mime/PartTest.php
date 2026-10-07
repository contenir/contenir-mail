<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Mime\Disposition;
use Contenir\Mail\Mime\Exception\InvalidArgumentException;
use Contenir\Mail\Mime\Mime;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Mime\TransferEncoding;
use Contenir\Mail\Tests\Unit\TestAsset\ShortReadStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function chr;
use function fclose;
use function fopen;
use function fwrite;
use function implode;
use function intdiv;
use function range;
use function str_repeat;
use function stream_context_create;
use function substr;

#[CoversClass(Part::class)]
#[Group('unit')]
final class PartTest extends TestCase
{
    #[Test]
    public function defaultsToBase64OctetStreamWithNoOptionalHeaders(): void
    {
        static::assertSame(
            "Content-Type: application/octet-stream\r\nContent-Transfer-Encoding: base64\r\n",
            (new Part('data'))->getHeaders()->toString(),
        );
    }

    #[Test]
    public function writesEveryOptionalHeaderInOrder(): void
    {
        $part = new Part(
            'data',
            type: 'text/plain',
            encoding: TransferEncoding::QuotedPrintable,
            charset: 'ISO-8859-1',
            disposition: Disposition::Attachment,
            filename: 'notes.txt',
            id: 'part1@example.com',
            description: 'Meeting notes',
            location: 'https://example.com/notes.txt',
            language: 'en-AU',
        );

        static::assertSame(
            "Content-Type: text/plain;\r\n charset=\"ISO-8859-1\"\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n"
                . "Content-ID: <part1@example.com>\r\n"
                . "Content-Disposition: attachment; filename=\"notes.txt\"\r\n"
                . "Content-Description: Meeting notes\r\n"
                . "Content-Location: https://example.com/notes.txt\r\n"
                . "Content-Language: en-AU\r\n",
            $part->getHeaders()->toString(),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('singleOptionalHeaderProvider')]
    #[Test]
    public function writesOnlyTheOptionalHeaderThatIsSet(array $arguments, string $expectedHeader): void
    {
        static::assertSame(
            "Content-Type: application/octet-stream\r\nContent-Transfer-Encoding: base64\r\n{$expectedHeader}",
            (new Part('data', ...$arguments))->getHeaders()->toString(),
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function singleOptionalHeaderProvider(): array
    {
        return [
            'content id'                       => [['id' => 'logo'], "Content-ID: <logo>\r\n"],
            'inline disposition'               => [
                ['disposition' => Disposition::Inline],
                "Content-Disposition: inline\r\n",
            ],
            'attachment disposition'           => [
                ['disposition' => Disposition::Attachment],
                "Content-Disposition: attachment\r\n",
            ],
            'disposition with filename'        => [
                ['disposition' => Disposition::Inline, 'filename' => 'logo.png'],
                "Content-Disposition: inline; filename=\"logo.png\"\r\n",
            ],
            'description'                      => [['description' => 'A logo'], "Content-Description: A logo\r\n"],
            'location'                         => [
                ['location' => 'https://example.com/a'],
                "Content-Location: https://example.com/a\r\n",
            ],
            'language'                         => [['language' => 'fr'], "Content-Language: fr\r\n"],
            'filename without any disposition' => [['filename' => 'ignored.txt'], ''],
        ];
    }

    #[Test]
    public function writesCharsetAsAContentTypeParameter(): void
    {
        static::assertSame(
            "Content-Type: text/plain;\r\n charset=\"UTF-8\"\r\nContent-Transfer-Encoding: 8bit\r\n",
            (new Part('data', type: 'text/plain', encoding: TransferEncoding::EightBit, charset: 'UTF-8'))->getHeaders()
                ->toString(),
        );
    }

    #[Test]
    public function exposesTheValuesItWasBuiltWith(): void
    {
        $part = new Part(
            'data',
            type: 'image/png',
            encoding: TransferEncoding::Binary,
            charset: 'US-ASCII',
            disposition: Disposition::Inline,
            filename: 'logo.png',
            id: 'logo@example.com',
        );

        static::assertSame(
            ['image/png', TransferEncoding::Binary, 'US-ASCII', Disposition::Inline, 'logo.png', 'logo@example.com'],
            [
                $part->getType(),
                $part->getTransferEncoding(),
                $part->getCharset(),
                $part->getDisposition(),
                $part->getFilename(),
                $part->getId(),
            ],
        );
    }

    #[Test]
    public function leavesOptionalValuesUnsetByDefault(): void
    {
        $part = new Part('data');

        static::assertSame(
            [Mime::TYPE_OCTETSTREAM, TransferEncoding::Base64, null, null, null, null],
            [
                $part->getType(),
                $part->getTransferEncoding(),
                $part->getCharset(),
                $part->getDisposition(),
                $part->getFilename(),
                $part->getId(),
            ],
        );
    }

    #[Test]
    public function buildsQuotedPrintableUtf8Text(): void
    {
        static::assertSame(
            "Content-Type: text/plain;\r\n charset=\"UTF-8\"\r\nContent-Transfer-Encoding: quoted-printable\r\n",
            Part::text('Hello')->getHeaders()->toString(),
        );
    }

    #[Test]
    public function buildsQuotedPrintableUtf8Html(): void
    {
        static::assertSame(
            "Content-Type: text/html;\r\n charset=\"UTF-8\"\r\nContent-Transfer-Encoding: quoted-printable\r\n",
            Part::html('<p>Hello</p>')->getHeaders()->toString(),
        );
    }

    #[Test]
    public function buildsTextInAnotherCharset(): void
    {
        static::assertSame('ISO-8859-1', Part::text('Hello', charset: 'ISO-8859-1')->getCharset());
    }

    #[Test]
    public function buildsHtmlInAnotherCharset(): void
    {
        static::assertSame('ISO-8859-1', Part::html('<p>Hello</p>', charset: 'ISO-8859-1')->getCharset());
    }

    #[Test]
    public function keepsTextContentAsGiven(): void
    {
        static::assertSame('Grüße', Part::text('Grüße')->getContent());
    }

    #[Test]
    public function keepsHtmlContentAsGiven(): void
    {
        static::assertSame('<p>Grüße</p>', Part::html('<p>Grüße</p>')->getContent());
    }

    #[Test]
    public function isALeaf(): void
    {
        $part = new Part('data');

        static::assertSame([false, []], [$part->isMultipart(), $part->getParts()]);
    }

    #[DataProvider('stringEncodingProvider')]
    #[Test]
    public function encodesStringContent(TransferEncoding $encoding, string $content, string $expected): void
    {
        static::assertSame($expected, (new Part($content, encoding: $encoding))->getEncodedContent());
    }

    /**
     * @return array<string, array{TransferEncoding, string, string}>
     */
    public static function stringEncodingProvider(): array
    {
        return [
            'base64'                       => [TransferEncoding::Base64, 'Hello, world', 'SGVsbG8sIHdvcmxk'],
            'base64 wrapped with crlf'     => [
                TransferEncoding::Base64,
                str_repeat('a', times: 60),
                str_repeat('YWFh', times: 18) . "\r\n" . str_repeat('YWFh', times: 2),
            ],
            'quoted-printable'             => [TransferEncoding::QuotedPrintable, 'Grüße = 1', 'Gr=C3=BC=C3=9Fe =3D 1'],
            'quoted-printable soft breaks' => [
                TransferEncoding::QuotedPrintable,
                str_repeat('a', times: 80),
                str_repeat('a', times: 72) . "=\r\n" . str_repeat('a', times: 8),
            ],
            '7bit unchanged'               => [TransferEncoding::SevenBit, "a\r\nb", "a\r\nb"],
            '8bit unchanged'               => [TransferEncoding::EightBit, 'Grüße', 'Grüße'],
            'binary unchanged'             => [TransferEncoding::Binary, "\x00\xFF", "\x00\xFF"],
        ];
    }

    #[Test]
    #[DataProvider('quotedPrintableLineBreakProvider')]
    public function writesTextLineBreaksAsHardBreaksOnlyForText(string $type, string $expected): void
    {
        static::assertSame(
            $expected,
            (new Part("one\ntwo", type: $type, encoding: TransferEncoding::QuotedPrintable))->getEncodedContent(),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function quotedPrintableLineBreakProvider(): array
    {
        return [
            'plain text' => ['text/plain', "one\r\ntwo"],
            'HTML'       => ['text/html', "one\r\ntwo"],
            'not text'   => ['application/json', 'one=0Atwo'],
            'text-like'  => ['application/text', 'one=0Atwo'],
        ];
    }

    #[Test]
    public function readsStreamContentFromTheStart(): void
    {
        $stream = self::temporaryStream('streamed content');

        static::assertSame('streamed content', (new Part($stream))->getContent());
    }

    #[Test]
    public function readsStreamContentTheSameWayTwice(): void
    {
        $part  = new Part(self::temporaryStream('streamed content'));
        $first = $part->getContent();

        static::assertSame($first, $part->getContent());
    }

    #[DataProvider('everyEncodingProvider')]
    #[Test]
    public function encodesStreamContentTheSameWayTwice(TransferEncoding $encoding): void
    {
        $part  = new Part(self::temporaryStream(self::bytes(200)), encoding: $encoding);
        $first = $part->getEncodedContent();

        static::assertSame($first, $part->getEncodedContent());
    }

    #[DataProvider('everyEncodingProvider')]
    #[Test]
    public function encodesStreamContentAsItWouldTheSameString(TransferEncoding $encoding): void
    {
        $content = 'Grüße = ' . self::bytes(150);

        static::assertSame(
            Mime::encode($content, $encoding, "\r\n"),
            (new Part(self::temporaryStream($content), encoding: $encoding))->getEncodedContent(),
        );
    }

    /**
     * @return array<string, array{TransferEncoding}>
     */
    public static function everyEncodingProvider(): array
    {
        return [
            '7bit'             => [TransferEncoding::SevenBit],
            '8bit'             => [TransferEncoding::EightBit],
            'binary'           => [TransferEncoding::Binary],
            'quoted-printable' => [TransferEncoding::QuotedPrintable],
            'base64'           => [TransferEncoding::Base64],
        ];
    }

    #[DataProvider('base64LengthProvider')]
    #[Test]
    public function encodesStreamAsBase64LikeAString(int $length): void
    {
        $content = self::bytes($length);

        static::assertSame(
            Mime::encode($content, TransferEncoding::Base64, "\r\n"),
            (new Part(self::temporaryStream($content)))->getEncodedContent(),
        );
    }

    #[DataProvider('shortReadProvider')]
    #[Test]
    public function encodesStreamAsBase64LikeAStringWhenReadsAreShort(int $length, int $readSize): void
    {
        $content = self::bytes($length);

        static::assertSame(
            Mime::encode($content, TransferEncoding::Base64, "\r\n"),
            (new Part(ShortReadStream::open($content, $readSize)))->getEncodedContent(),
        );
    }

    #[Test]
    public function encodesAnEmptyStreamAsNothing(): void
    {
        static::assertSame('', (new Part(self::temporaryStream('')))->getEncodedContent());
    }

    #[Test]
    public function encodesStreamAsBase64LinesOf72Characters(): void
    {
        $content = str_repeat('abc', times: 37);

        static::assertSame(
            str_repeat('YWJj', times: 18) . "\r\n" . str_repeat('YWJj', times: 18) . "\r\n" . 'YWJj',
            (new Part(self::temporaryStream($content)))->getEncodedContent(),
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function base64LengthProvider(): array
    {
        return [
            'no bytes'                    => [0],
            'one byte'                    => [1],
            'one short of a line'         => [53],
            'exactly one line'            => [54],
            'one over a line'             => [55],
            'two lines'                   => [108],
            'one over a full read'        => [(54 * 1024) + 1],
            'one short of two full reads' => [(54 * 1024 * 2) - 1],
        ];
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function shortReadProvider(): array
    {
        return [
            'one byte at a time'              => [55, 1],
            'reads of seven bytes'            => [200, 7],
            'reads one short of a line'       => [163, 53],
            'reads of a line'                 => [163, 54],
            'reads one over a line'           => [163, 55],
            'reads not a multiple of three'   => [(54 * 1024) + 1, 1000],
            'reads of the default chunk size' => [(54 * 1024 * 2) + 5, 8192],
        ];
    }

    #[DataProvider('invalidContentProvider')]
    #[Test]
    public function rejectsContentThatIsNeitherAStringNorAStream(mixed $content): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Content must be a string or a stream');

        new Part($content);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidContentProvider(): array
    {
        return [
            'null'           => [null],
            'false'          => [false],
            'true'           => [true],
            'zero'           => [0],
            'integer'        => [1],
            'float'          => [1.1],
            'array'          => [['string']],
            'object'         => [(object) ['content' => 'string']],
            'stream context' => [stream_context_create()],
        ];
    }

    #[Test]
    public function rejectsAClosedStream(): void
    {
        $stream = self::temporaryStream('data');
        fclose($stream);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Content must be a string or a stream');

        new Part($stream);
    }

    /**
     * Every byte value in turn, repeated to the length asked for.
     */
    private static function bytes(int $length): string
    {
        $all = implode('', array_map(chr(...), range(
            start: 0,
            end: 255,
        )));

        return substr(str_repeat($all, intdiv($length, num2: 256) + 1), offset: 0, length: $length);
    }

    /**
     * @return resource
     */
    private static function temporaryStream(string $content)
    {
        $stream = fopen('php://temp', mode: 'w+b');
        if (false === $stream) {
            throw new RuntimeException('Cannot open a temporary stream');
        }

        fwrite($stream, $content);

        return $stream;
    }
}
