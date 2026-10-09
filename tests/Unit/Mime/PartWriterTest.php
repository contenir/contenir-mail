<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Closure;
use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Headers;
use Contenir\Mail\Mime\Disposition;
use Contenir\Mail\Mime\Exception\InvalidArgumentException;
use Contenir\Mail\Mime\Exception\RuntimeException;
use Contenir\Mail\Mime\Multipart;
use Contenir\Mail\Mime\MultipartType;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Mime\PartInterface;
use Contenir\Mail\Mime\PartWriter;
use Contenir\Mail\Mime\TransferEncoding;
use Contenir\Mail\Storage\Part as StoragePart;
use Contenir\Mail\Tests\TestAsset\PartWithHeaders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function chr;
use function fopen;
use function fwrite;
use function implode;
use function intdiv;
use function range;
use function str_repeat;
use function stream_get_contents;
use function substr;

#[CoversClass(PartWriter::class)]
#[Group('unit')]
final class PartWriterTest extends TestCase
{
    #[Test]
    public function writesALeafAsItsEncodedContent(): void
    {
        static::assertSame('SGVsbG8=', PartWriter::body(new Part('Hello')));
    }

    #[Test]
    public function writesEachChildBetweenBoundaryLines(): void
    {
        $multipart = new Multipart(
            MultipartType::Alternative,
            [Part::text('Hello'), Part::html('<p>Hello</p>')],
            boundary: 'frontier',
        );

        static::assertSame(
            "--frontier\r\n"
                . "Content-Type: text/plain; charset=\"UTF-8\"\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n"
                . "\r\n"
                . "Hello\r\n"
                . "--frontier\r\n"
                . "Content-Type: text/html; charset=\"UTF-8\"\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n"
                . "\r\n"
                . "<p>Hello</p>\r\n"
                . '--frontier--',
            PartWriter::body($multipart),
        );
    }

    #[Test]
    public function writesASingleChild(): void
    {
        $multipart = new Multipart(MultipartType::Mixed, [new Part('abc')], boundary: 'b');

        static::assertSame(
            "--b\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: base64\r\n\r\nYWJj\r\n--b--",
            PartWriter::body($multipart),
        );
    }

    #[Test]
    public function writesNestedMultipartsWithTheirOwnBoundaries(): void
    {
        $alternative = new Multipart(
            MultipartType::Alternative,
            [Part::text('Hi'), Part::html('<b>Hi</b>')],
            boundary: 'inner',
        );
        $mixed = new Multipart(
            MultipartType::Mixed,
            [$alternative, new Part('abc', encoding: TransferEncoding::SevenBit)],
            boundary: 'outer',
        );

        static::assertSame(
            "--outer\r\n"
                . "Content-Type: multipart/alternative; boundary=\"inner\"\r\n"
                . "\r\n"
                . "--inner\r\n"
                . "Content-Type: text/plain; charset=\"UTF-8\"\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n"
                . "\r\n"
                . "Hi\r\n"
                . "--inner\r\n"
                . "Content-Type: text/html; charset=\"UTF-8\"\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n"
                . "\r\n"
                . "<b>Hi</b>\r\n"
                . "--inner--\r\n"
                . "--outer\r\n"
                . "Content-Type: application/octet-stream\r\n"
                . "Content-Transfer-Encoding: 7bit\r\n"
                . "\r\n"
                . "abc\r\n"
                . '--outer--',
            PartWriter::body($mixed),
        );
    }

    #[Test]
    public function writesALeafWithEmptyContentAsAnEmptyLine(): void
    {
        $multipart = new Multipart(
            MultipartType::Mixed,
            [new Part('', encoding: TransferEncoding::SevenBit)],
            boundary: 'b',
        );

        static::assertSame(
            "--b\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: 7bit\r\n\r\n\r\n--b--",
            PartWriter::body($multipart),
        );
    }

    #[DataProvider('headersWithoutBoundaryProvider')]
    #[Test]
    public function rejectsAMultipartWithNoBoundary(Headers $headers): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A multipart part has no boundary in its Content-Type');

        PartWriter::body(new PartWithHeaders(
            multipart: true,
            headers: $headers,
        ));
    }

    /**
     * @return array<string, array{Headers}>
     */
    public static function headersWithoutBoundaryProvider(): array
    {
        return [
            'no content type'          => [new Headers()],
            'no boundary parameter'    => [new Headers(new ContentType('multipart/mixed'))],
            'empty boundary parameter' => [new Headers(new ContentType('multipart/mixed', ['boundary' => '']))],
        ];
    }

    #[Test]
    public function writesAMultipartFromAnyImplementation(): void
    {
        $multipart = new PartWithHeaders(
            multipart: true,
            headers: new Headers(new ContentType('multipart/mixed', [
                'boundary' => 'custom',
            ])),
        );

        static::assertSame(
            "--custom\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: base64\r\n\r\neA==\r\n--custom--",
            PartWriter::body($multipart),
        );
    }

    /**
     * RFC 2046, section 5.1.1: the boundary must not appear in a part, on a
     * line by itself or as the prefix of a line.
     */
    #[Test]
    #[DataProvider('collidingPartProvider')]
    public function refusesPartContainingItsBoundary(Part|Multipart $child): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'A part contains a line starting with its multipart boundary "frontier"; choose another boundary',
        );

        PartWriter::body(new Multipart(MultipartType::Mixed, [$child], boundary: 'frontier'));
    }

    /**
     * @return array<string, array{Part|Multipart}>
     */
    public static function collidingPartProvider(): array
    {
        return [
            'first line'        => [new Part('--frontier', encoding: TransferEncoding::SevenBit)],
            'later line'        => [new Part("one\r\n--frontier\r\ntwo", encoding: TransferEncoding::SevenBit)],
            'prefix of a line'  => [new Part("one\r\n--frontier-and-more", encoding: TransferEncoding::SevenBit)],
            'closing delimiter' => [new Part("one\r\n--frontier--", encoding: TransferEncoding::SevenBit)],
            'nested boundary'   => [new Multipart(MultipartType::Alternative, [new Part('x')], boundary: 'frontier2')],
        ];
    }

    #[Test]
    #[DataProvider('separatePartProvider')]
    public function writesPartWhoseLinesDoNotStartWithItsBoundary(string $content): void
    {
        static::assertStringContainsString(
            $content,
            PartWriter::body(
                new Multipart(
                    MultipartType::Mixed,
                    [
                        new Part($content, encoding: TransferEncoding::SevenBit),
                    ],
                    boundary: 'frontier',
                ),
            ),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function separatePartProvider(): array
    {
        return [
            'after other text' => ['see --frontier'],
            'after a space'    => [' --frontier'],
            'single dash'      => ['-frontier'],
            'other boundary'   => ['--frontline'],
        ];
    }

    /**
     * Boundaries may hold characters that are special in a regular expression, such as "." and "/".
     */
    #[Test]
    public function readsBoundaryCharactersLiterally(): void
    {
        static::assertStringContainsString(
            '--axb/c',
            PartWriter::body(
                new Multipart(
                    MultipartType::Mixed,
                    [
                        new Part('--axb/c', encoding: TransferEncoding::SevenBit),
                    ],
                    boundary: 'a.b/c',
                ),
            ),
        );
    }

    /**
     * @param Closure(): PartInterface $part
     */
    #[DataProvider('shapeProvider')]
    #[Test]
    public function writesToAStreamWhatBodyReturns(Closure $part): void
    {
        $stream = self::memory();
        PartWriter::write($part(), $stream);

        static::assertSame(PartWriter::body($part()), stream_get_contents($stream, offset: 0));
    }

    #[Test]
    public function writesALeafOfAnotherImplementationAsItsEncodedContent(): void
    {
        $stream = self::memory();
        PartWriter::write(StoragePart::fromString("Content-Type: text/plain\r\n\r\nHello"), $stream);

        static::assertSame('Hello', stream_get_contents($stream, offset: 0));
    }

    #[Test]
    public function writesAfterWhatTheStreamAlreadyHolds(): void
    {
        $stream = self::memory();
        fwrite($stream, data: 'before ');
        PartWriter::write(new Part('Hello'), $stream);

        static::assertSame('before SGVsbG8=', stream_get_contents($stream, offset: 0));
    }

    #[Test]
    public function refusesToWriteToSomethingOtherThanAStream(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected an open stream');

        PartWriter::write(new Part('Hello'), 'php://memory');
    }

    #[Test]
    public function failsWhenTheStreamCannotBeWritten(): void
    {
        $stream = fopen(__FILE__, mode: 'rb');
        static::assertNotFalse($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot write the message to the stream');

        PartWriter::write(new Part('Hello'), $stream);
    }

    #[DataProvider('collidingPartProvider')]
    #[Test]
    public function refusesPartContainingItsBoundaryWhileWriting(Part|Multipart $child): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'A part contains a line starting with its multipart boundary "frontier"; choose another boundary',
        );

        PartWriter::write(new Multipart(MultipartType::Mixed, [$child], boundary: 'frontier'), self::memory());
    }

    /**
     * @return array<string, array{Closure(): PartInterface}>
     */
    public static function shapeProvider(): array
    {
        return [
            'text'                 => [static fn(): PartInterface => Part::text("Hello\nworld")],
            'html'                 => [static fn(): PartInterface => Part::html('<p>Hello</p>')],
            'empty leaf'           => [
                static fn(): PartInterface => new Part('', encoding: TransferEncoding::SevenBit),
            ],
            'empty stream'         => [static fn(): PartInterface => new Part(self::temporary(''))],
            'stream of reads'      => [static fn(): PartInterface => new Part(self::temporary(self::bytes(200_000)))],
            'alternative'          => [
                static fn(): PartInterface => new Multipart(
                    MultipartType::Alternative,
                    [Part::text('Hi'), Part::html('<b>Hi</b>')],
                    boundary: 'alt',
                ),
            ],
            'attachments'          => [
                static fn(): PartInterface => new Multipart(
                    MultipartType::Mixed,
                    [
                        Part::text('See attached'),
                        new Part(self::temporary(self::bytes(150_000)), disposition: Disposition::Attachment),
                        new Part('small', 'text/csv', disposition: Disposition::Attachment, filename: 'a.csv'),
                    ],
                    boundary: 'mixed',
                ),
            ],
            'embedded'             => [
                static fn(): PartInterface => new Multipart(
                    MultipartType::Related,
                    [Part::html('<img src="cid:logo">'), new Part(self::temporary('PNG'), 'image/png', id: 'logo')],
                    boundary: 'related',
                ),
            ],
            'nested'               => [
                static fn(): PartInterface => new Multipart(
                    MultipartType::Mixed,
                    [
                        new Multipart(
                            MultipartType::Alternative,
                            [Part::text('Hi'), Part::html('<b>Hi</b>')],
                            boundary: 'inner',
                        ),
                        new Part(self::temporary(self::bytes(60_000))),
                    ],
                    boundary: 'mixed',
                ),
            ],
            'other implementation' => [
                static fn(): PartInterface => new PartWithHeaders(
                    multipart: true,
                    headers: new Headers(new ContentType('multipart/mixed', ['boundary' => 'custom'])),
                ),
            ],
        ];
    }

    /**
     * Every byte value in turn, repeated to the length asked for.
     */
    private static function bytes(int $length): string
    {
        return substr(
            str_repeat(implode('', array_map(chr(...), range(
                start: 0,
                end: 255,
            ))), intdiv($length, num2: 256) + 1),
            offset: 0,
            length: $length,
        );
    }

    /**
     * @return resource
     */
    private static function temporary(string $content)
    {
        $stream = self::memory();
        fwrite($stream, $content);

        return $stream;
    }

    /**
     * @return resource
     */
    private static function memory()
    {
        $stream = fopen('php://temp', mode: 'w+b');
        static::assertNotFalse($stream);

        return $stream;
    }
}
