<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\ContentTransferEncoding;
use Contenir\Mail\Header\Exception;
use Contenir\Mail\Header\HeaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;
use function strtolower;
use function strtoupper;
use function substr;

#[CoversClass(ContentTransferEncoding::class)]
class ContentTransferEncodingTest extends TestCase
{
    public static function dataValidEncodings(): array
    {
        return [
            ['7bit'],
            ['8bit'],
            ['binary'],
            ['quoted-printable'],
        ];
    }

    public static function dataInvalidEncodings(): array
    {
        return [
            ['9bit'],
            ['x-something'],
        ];
    }

    #[Test]
    #[DataProvider('dataValidEncodings')]
    public function contentTransferEncodingFromStringCreatesValidContentTransferEncodingHeader(
        string $encoding,
    ): void {
        $contentTransferEncodingHeader = ContentTransferEncoding::fromString("Content-Transfer-Encoding: {$encoding}");
        static::assertInstanceOf(HeaderInterface::class, $contentTransferEncodingHeader);
        static::assertInstanceOf(ContentTransferEncoding::class, $contentTransferEncodingHeader);
    }

    #[Test]
    #[DataProvider('dataInvalidEncodings')]
    public function contentTransferEncodingFromStringRaisesException(string $encoding): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $contentTransferEncodingHeader = ContentTransferEncoding::fromString("Content-Transfer-Encoding: {$encoding}");
    }

    #[Test]
    public function contentTransferEncodingGetFieldNameReturnsHeaderName(): void
    {
        $contentTransferEncodingHeader = new ContentTransferEncoding();
        static::assertSame('Content-Transfer-Encoding', $contentTransferEncodingHeader->getFieldName());
    }

    #[Test]
    #[DataProvider('dataValidEncodings')]
    public function contentTransferEncodingGetFieldValueReturnsProperValue(string $encoding): void
    {
        $contentTransferEncodingHeader = new ContentTransferEncoding();
        $contentTransferEncodingHeader->setTransferEncoding($encoding);
        static::assertSame($encoding, $contentTransferEncodingHeader->getFieldValue());
        static::assertSame($encoding, $contentTransferEncodingHeader->getTransferEncoding());
    }

    #[Test]
    #[DataProvider('dataValidEncodings')]
    public function contentTransferEncodingHandlesCaseInsensitivity(string $encoding): void
    {
        $header = new ContentTransferEncoding();
        $header->setTransferEncoding(strtoupper(substr($encoding, 0, 4)) . substr($encoding, 4));
        static::assertSame(strtolower($encoding), strtolower($header->getFieldValue()));
    }

    #[Test]
    #[DataProvider('dataValidEncodings')]
    public function contentTransferEncodingToStringReturnsHeaderFormattedString(string $encoding): void
    {
        $contentTransferEncodingHeader = new ContentTransferEncoding();
        $contentTransferEncodingHeader->setTransferEncoding($encoding);
        static::assertSame("Content-Transfer-Encoding: {$encoding}", $contentTransferEncodingHeader->toString());
    }

    #[Test]
    public function providingParametersIntroducesHeaderFolding(): void
    {
        $header = new ContentTransferEncoding();
        $header->setTransferEncoding('quoted-printable');
        $string = $header->toString();

        static::assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $string);
    }

    #[Test]
    #[Group('ZF2015-04')]
    public function fromStringRaisesExceptionOnInvalidHeaderName(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        ContentTransferEncoding::fromString('Content-Transfer-Encoding' . chr(32) . ': 8bit');
    }

    public static function headerLines(): array
    {
        return [
            'newline'   => ["Content-Transfer-Encoding: 8bit\n7bit"],
            'cr-lf'     => ["Content-Transfer-Encoding: 8bit\r\n7bit"],
            'multiline' => ["Content-Transfer-Encoding: 8bit\r\n7bit\r\nUTF-8"],
        ];
    }

    #[Test]
    #[DataProvider('headerLines')]
    #[Group('ZF2015-04')]
    public function fromStringRaisesExceptionForInvalidMultilineValues(string $headerLine): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        ContentTransferEncoding::fromString($headerLine);
    }

    #[Test]
    #[Group('ZF2015-04')]
    public function fromStringRaisesExceptionForContinuations(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('expects');
        ContentTransferEncoding::fromString("Content-Transfer-Encoding: 8bit\r\n 7bit");
    }

    #[Test]
    #[Group('ZF2015-04')]
    public function setTransferEncodingRaisesExceptionForInvalidValues(): void
    {
        $header = new ContentTransferEncoding();
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('expects');
        $header->setTransferEncoding("8bit\r\n 7bit");
    }

    #[Test]
    public function fromStringRaisesExceptionOnInvalidHeader(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for Content-Transfer-Encoding string');
        ContentTransferEncoding::fromString('Foo: bar');
    }

    #[Test]
    public function defaultEncoding(): void
    {
        $header = new ContentTransferEncoding();
        static::assertSame('ASCII', $header->getEncoding());
    }

    #[Test]
    public function changeEncodingHasNoEffect(): void
    {
        $header = new ContentTransferEncoding();
        $header->setEncoding('UTF-8');
        static::assertSame('ASCII', $header->getEncoding());
    }

    public static function unconventionalHeaderLinesProvider(): array
    {
        return [
            // Description => [header line, expected value]
            'contenttransferencoding'   => ['ContentTransferEncoding: 7bit', '7bit'],
            'content_transfer_encoding' => ['Content_Transfer_Encoding: 7bit', '7bit'],
        ];
    }

    #[Test]
    #[DataProvider('unconventionalHeaderLinesProvider')]
    public function fromStringHandlesUnconventionalNames(string $headerLine, string $expected): void
    {
        $header = ContentTransferEncoding::fromString($headerLine);
        static::assertInstanceOf(ContentTransferEncoding::class, $header);
        static::assertSame('Content-Transfer-Encoding', $header->getFieldName());
        static::assertSame($expected, $header->getFieldValue());
    }
}
