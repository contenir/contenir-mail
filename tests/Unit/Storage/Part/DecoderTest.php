<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Part;

use Contenir\Mail\Mime\TransferEncoding;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\FileSystem;
use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\Decoder;
use Contenir\Mail\Storage\Part\Lines;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function base64_encode;
use function chr;
use function chunk_split;
use function fopen;
use function preg_replace;
use function quoted_printable_decode;
use function quoted_printable_encode;
use function rewind;
use function str_repeat;
use function stream_get_contents;
use function strlen;

#[CoversClass(Decoder::class)]
#[CoversClass(Content::class)]
#[CoversClass(Lines::class)]
#[CoversClass(FileSystem::class)]
#[Group('unit')]
final class DecoderTest extends TestCase
{
    /**
     * Decoding a block at a time gives what decoding the whole content at once gave.
     */
    #[DataProvider('encodedProvider')]
    #[Test]
    public function decodesAsTheWholeContentDecodes(string $encoded, ?TransferEncoding $encoding): void
    {
        static::assertSame(
            self::decodeWhole($encoded, $encoding),
            Decoder::join(Decoder::decode(Content::fromString($encoded), $encoding)),
        );
    }

    #[Test]
    public function decodesBase64InPiecesOfWholeGroups(): void
    {
        $encoded = str_repeat('QUJD', times: Content::BLOCK / 4) . "\r\nREVG";

        static::assertSame(
            [(Content::BLOCK / 4) * 3, 3, 0],
            self::lengths(Decoder::decode(Content::fromString($encoded), TransferEncoding::Base64)),
        );
    }

    #[Test]
    public function stopsDecodingQuotedPrintableAtANulByte(): void
    {
        $encoded = "a\0b\n" . str_repeat("c\n", times: Content::BLOCK);

        static::assertSame(
            'a',
            Decoder::join(Decoder::decode(Content::fromString($encoded), TransferEncoding::QuotedPrintable)),
        );
    }

    #[Test]
    public function writesThePiecesToAStream(): void
    {
        $stream = fopen('php://memory', mode: 'w+b');
        $count  = Decoder::write(['ab', '', 'cde'], $stream);
        rewind($stream);

        static::assertSame([5, 'abcde'], [$count, stream_get_contents($stream)]);
    }

    #[Test]
    public function refusesAStreamThatCannotBeWritten(): void
    {
        $stream = fopen('php://memory', mode: 'rb');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot write the content to the stream');

        Decoder::write(['ab'], $stream);
    }

    #[Test]
    public function writesNothingForNoPieces(): void
    {
        static::assertSame(0, Decoder::write([], fopen('php://memory', mode: 'rb')));
    }

    #[Test]
    public function convertsBareLineFeedsToCrlf(): void
    {
        static::assertSame("a\r\nb\r\nc", Decoder::crlf("a\nb\r\nc"));
    }

    /**
     * @return array<string, array{string, TransferEncoding|null}>
     */
    public static function encodedProvider(): array
    {
        $binary = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $binary .= chr($byte);
        }

        $binary = str_repeat($binary, times: 300);
        $text   = str_repeat("Grüße, tschüß = 100%\tand a long line " . str_repeat('x', times: 90) . "\n", times: 600);

        return [
            'base64 in CRLF lines'           => [chunk_split(base64_encode($binary)), TransferEncoding::Base64],
            'base64 in LF lines'             => [
                chunk_split(base64_encode($binary), length: 76, separator: "\n"),
                TransferEncoding::Base64,
            ],
            'base64 on one line'             => [base64_encode($binary), TransferEncoding::Base64],
            'base64 with stray characters'   => [
                chunk_split(base64_encode($binary), length: 50, separator: " *!\r\n"),
                TransferEncoding::Base64,
            ],
            'base64 with padding in between' => [str_repeat("YWI=\r\n", times: 14_000), TransferEncoding::Base64],
            'base64 cut short'               => [base64_encode($binary) . 'QUJ', TransferEncoding::Base64],
            'base64 with one character over' => [base64_encode($binary) . 'Q', TransferEncoding::Base64],
            'quoted-printable in CRLF lines' => [quoted_printable_encode($text), TransferEncoding::QuotedPrintable],
            'quoted-printable in LF lines'   => [
                (string) preg_replace('/\r\n/', replacement: "\n", subject: quoted_printable_encode($text)),
                TransferEncoding::QuotedPrintable,
            ],
            'quoted-printable soft breaks'   => [
                str_repeat("ab=\r\ncd=3D=\n", times: 6_000),
                TransferEncoding::QuotedPrintable,
            ],
            'quoted-printable on one line'   => [str_repeat('=41', times: 30_000), TransferEncoding::QuotedPrintable],
            'quoted-printable equals at end' => [
                str_repeat('a', times: Content::BLOCK - 1) . "=\n=  \nb=",
                TransferEncoding::QuotedPrintable,
            ],
            '8bit'                           => [$text, TransferEncoding::EightBit],
            '7bit'                           => [str_repeat("plain\r\n", times: 10_000), TransferEncoding::SevenBit],
            'binary'                         => [$binary, TransferEncoding::Binary],
            'no encoding'                    => [$text, null],
        ];
    }

    /**
     * Decoding as Part::getContent() did before decoding a block at a time.
     *
     * @mago-expect lint:strict-behavior The decoding being compared with is lenient.
     */
    private static function decodeWhole(string $encoded, ?TransferEncoding $encoding): string
    {
        return match ($encoding) {
            TransferEncoding::Base64 => (string) base64_decode(
                (string) preg_replace('/[^A-Za-z0-9+\/=]/', replacement: '', subject: $encoded),
            ),
            TransferEncoding::QuotedPrintable => quoted_printable_decode(Decoder::crlf($encoded)),
            default                           => $encoded,
        };
    }

    /**
     * @param iterable<string> $pieces
     * @return list<int>
     */
    private static function lengths(iterable $pieces): array
    {
        $lengths = [];
        foreach ($pieces as $piece) {
            $lengths[] = strlen($piece);
        }

        return $lengths;
    }
}
