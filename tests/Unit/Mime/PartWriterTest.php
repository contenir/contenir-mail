<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Headers;
use Contenir\Mail\Mime\Exception\RuntimeException;
use Contenir\Mail\Mime\Multipart;
use Contenir\Mail\Mime\MultipartType;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Mime\PartWriter;
use Contenir\Mail\Mime\TransferEncoding;
use Contenir\Mail\Tests\Unit\TestAsset\PartWithHeaders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
                . "Content-Type: text/plain;\r\n charset=\"UTF-8\"\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n"
                . "\r\n"
                . "Hello\r\n"
                . "--frontier\r\n"
                . "Content-Type: text/html;\r\n charset=\"UTF-8\"\r\n"
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
                . "Content-Type: multipart/alternative;\r\n boundary=\"inner\"\r\n"
                . "\r\n"
                . "--inner\r\n"
                . "Content-Type: text/plain;\r\n charset=\"UTF-8\"\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n"
                . "\r\n"
                . "Hi\r\n"
                . "--inner\r\n"
                . "Content-Type: text/html;\r\n charset=\"UTF-8\"\r\n"
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
}
