<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\ContentTransferEncoding;
use Contenir\Mail\Header\Exception;
use Contenir\Mail\Mime\TransferEncoding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentTransferEncoding::class)]
#[CoversClass(TransferEncoding::class)]
#[Group('unit')]
final class ContentTransferEncodingTest extends TestCase
{
    #[Test]
    public function reportsFieldName(): void
    {
        static::assertSame(
            'Content-Transfer-Encoding',
            (new ContentTransferEncoding(TransferEncoding::SevenBit))->getFieldName(),
        );
    }

    #[DataProvider('encodingProvider')]
    #[Test]
    public function exposesTheTransferEncodingItWasGiven(TransferEncoding $encoding): void
    {
        static::assertSame($encoding, (new ContentTransferEncoding($encoding))->getTransferEncoding());
    }

    #[DataProvider('encodingProvider')]
    #[Test]
    public function rendersMechanismAsFieldValue(TransferEncoding $encoding, string $value): void
    {
        static::assertSame($value, (new ContentTransferEncoding($encoding))->getFieldValue());
    }

    #[DataProvider('encodingProvider')]
    #[Test]
    public function rendersMechanismAsEncodedFieldValue(TransferEncoding $encoding, string $value): void
    {
        static::assertSame($value, (new ContentTransferEncoding($encoding))->getEncodedFieldValue());
    }

    #[DataProvider('encodingProvider')]
    #[Test]
    public function rendersHeaderLine(TransferEncoding $encoding, string $value): void
    {
        static::assertSame(
            "Content-Transfer-Encoding: {$value}",
            (new ContentTransferEncoding($encoding))->toString(),
        );
    }

    #[DataProvider('encodingProvider')]
    #[Test]
    public function parsesMechanismFromString(TransferEncoding $encoding, string $value): void
    {
        static::assertSame(
            $encoding,
            ContentTransferEncoding::fromString("Content-Transfer-Encoding: {$value}")->getTransferEncoding(),
        );
    }

    #[DataProvider('caseInsensitiveMechanismProvider')]
    #[Test]
    public function parsesMechanismCaseInsensitively(string $headerLine, TransferEncoding $expected): void
    {
        static::assertSame($expected, ContentTransferEncoding::fromString($headerLine)->getTransferEncoding());
    }

    #[DataProvider('unconventionalHeaderNameProvider')]
    #[Test]
    public function acceptsUnconventionalHeaderNames(string $headerLine): void
    {
        static::assertSame(
            TransferEncoding::SevenBit,
            ContentTransferEncoding::fromString($headerLine)->getTransferEncoding(),
        );
    }

    #[DataProvider('unknownMechanismProvider')]
    #[Test]
    public function rejectsUnknownMechanism(string $headerLine, string $message): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ContentTransferEncoding::fromString($headerLine);
    }

    #[DataProvider('injectedHeaderLineProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsHeaderLineWithLineBreaksInValue(string $headerLine): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header value detected');

        ContentTransferEncoding::fromString($headerLine);
    }

    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsHeaderNameWithTrailingSpace(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header name detected');

        ContentTransferEncoding::fromString('Content-Transfer-Encoding : 8bit');
    }

    #[Test]
    public function rejectsHeaderLineOfAnotherHeader(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for Content-Transfer-Encoding string');

        ContentTransferEncoding::fromString('Foo: bar');
    }

    /**
     * @return array<string, array{TransferEncoding, string}>
     */
    public static function encodingProvider(): array
    {
        return [
            '7bit'             => [TransferEncoding::SevenBit, '7bit'],
            '8bit'             => [TransferEncoding::EightBit, '8bit'],
            'binary'           => [TransferEncoding::Binary, 'binary'],
            'quoted-printable' => [TransferEncoding::QuotedPrintable, 'quoted-printable'],
            'base64'           => [TransferEncoding::Base64, 'base64'],
        ];
    }

    /**
     * @return array<string, array{string, TransferEncoding}>
     */
    public static function caseInsensitiveMechanismProvider(): array
    {
        return [
            'upper-case base64'      => ['Content-Transfer-Encoding: BASE64', TransferEncoding::Base64],
            'mixed-case 8bit'        => ['Content-Transfer-Encoding: 8BIT', TransferEncoding::EightBit],
            'title-case quoted'      => [
                'Content-Transfer-Encoding: Quoted-Printable',
                TransferEncoding::QuotedPrintable,
            ],
            'trailing whitespace'    => ['Content-Transfer-Encoding: binary ', TransferEncoding::Binary],
            'lower-case header name' => ['content-transfer-encoding: 7bit', TransferEncoding::SevenBit],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unconventionalHeaderNameProvider(): array
    {
        return [
            'no separators'         => ['ContentTransferEncoding: 7bit'],
            'underscore separators' => ['Content_Transfer_Encoding: 7bit'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unknownMechanismProvider(): array
    {
        return [
            '9bit'             => ['Content-Transfer-Encoding: 9bit', 'Unknown Content-Transfer-Encoding "9bit"'],
            'extension token'  => [
                'Content-Transfer-Encoding: x-something',
                'Unknown Content-Transfer-Encoding "x-something"',
            ],
            'empty'            => ['Content-Transfer-Encoding: ', 'Unknown Content-Transfer-Encoding ""'],
            'folded mechanism' => [
                "Content-Transfer-Encoding: 8bit\r\n 7bit",
                'Unknown Content-Transfer-Encoding "8bit 7bit"',
            ],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedHeaderLineProvider(): array
    {
        return [
            'newline'   => ["Content-Transfer-Encoding: 8bit\n7bit"],
            'cr-lf'     => ["Content-Transfer-Encoding: 8bit\r\n7bit"],
            'multiline' => ["Content-Transfer-Encoding: 8bit\r\n7bit\r\nUTF-8"],
        ];
    }
}
