<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\Exception;
use Contenir\Mail\Header\Received;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

#[CoversClass(Received::class)]
#[Group('unit')]
final class ReceivedTest extends TestCase
{
    private const string TRACE = 'from mail.example.com by mx.example.org; Mon, 1 Jan 2024 00:00:00 +0000';

    #[Test]
    public function reportsFieldName(): void
    {
        static::assertSame('Received', (new Received('test'))->getFieldName());
    }

    #[Test]
    public function keepsValueAsWritten(): void
    {
        static::assertSame(self::TRACE, (new Received(self::TRACE))->getFieldValue());
    }

    #[Test]
    public function rendersValueUnchangedAsEncodedFieldValue(): void
    {
        static::assertSame(self::TRACE, (new Received(self::TRACE))->getEncodedFieldValue());
    }

    #[Test]
    public function rendersHeaderLine(): void
    {
        static::assertSame('Received: ' . self::TRACE, (new Received(self::TRACE))->toString());
    }

    #[Test]
    public function acceptsFoldedValue(): void
    {
        $value = "from mail.example.com\r\n by mx.example.org";

        static::assertSame("Received: {$value}", (new Received($value))->toString());
    }

    #[Test]
    public function parsesValueFromString(): void
    {
        static::assertSame(self::TRACE, Received::fromString('Received: ' . self::TRACE)->getFieldValue());
    }

    #[Test]
    public function parsesHeaderNameCaseInsensitively(): void
    {
        static::assertSame('test', Received::fromString('RECEIVED: test')->getFieldValue());
    }

    #[Test]
    public function unfoldsFoldedValueFromString(): void
    {
        static::assertSame(
            'from mail.example.com by mx.example.org',
            Received::fromString("Received: from mail.example.com\r\n by mx.example.org")->getFieldValue(),
        );
    }

    #[DataProvider('injectedHeaderLineProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsHeaderLineWithLineBreaksInValue(string $headerLine): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header value detected');

        Received::fromString($headerLine);
    }

    #[DataProvider('invalidValueProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsValueWithLineBreaksOrNonAsciiCharacters(string $value): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Received value provided');

        new Received($value);
    }

    #[Test]
    public function rejectsEncodedWordDecodingToNonAsciiCharacters(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Received value provided');

        Received::fromString('Received: =?UTF-8?Q?=C3=A1?=');
    }

    #[Test]
    public function rejectsHeaderLineOfAnotherHeader(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for Received string');

        Received::fromString('Foo: bar');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedHeaderLineProvider(): array
    {
        return [
            'newline'     => ["Received: xx\nx"],
            'cr-lf'       => ["Received: xxx\r\n"],
            'cr-lf fold'  => ["Received: xxx\r\n\r\n zzz"],
            'cr-lf twice' => ["Received: xx\r\n\r\nx"],
            'multiline'   => ["Received: x\r\nx\r\nx"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidValueProvider(): array
    {
        return [
            'newline'     => ["xx\nx"],
            'cr-lf'       => ["xxx\r\n"],
            'cr-lf twice' => ["xx\r\n\r\nx"],
            'multiline'   => ["x\r\nx\r\nx"],
            'non-ASCII'   => ['á'],
        ];
    }

    #[Test]
    public function acceptsLineThatFitsLineLimit(): void
    {
        $value = 'from mail.example.com ' . str_repeat('a', times: 966);

        static::assertSame(998, strlen((new Received($value))->toString()));
    }

    #[Test]
    public function acceptsLongValueFoldedIntoShortLines(): void
    {
        $value = str_repeat("from mail.example.com\r\n\t", times: 60) . 'by mx.example.com';

        static::assertSame("Received: {$value}", (new Received($value))->toString());
    }

    /**
     * Received is written as it is, so a line too long for the limit is refused.
     */
    #[Test]
    public function rejectsLineTooLongForLineLimit(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A Received line may be at most 988 characters, so that it fits in 998 with its name',
        );

        new Received('from mail.example.com ' . str_repeat('a', times: 967));
    }
}
