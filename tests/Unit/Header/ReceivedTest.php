<?php

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header;
use Contenir\Mail\Header\Exception;
use Contenir\Mail\Header\HeaderInterface;
use Contenir\Mail\Header\Received;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Received::class)]
class ReceivedTest extends TestCase
{
    #[Test]
    public function fromStringCreatesValidReceivedHeader(): void
    {
        $receivedHeader = Header\Received::fromString('Received: xxx');
        static::assertInstanceOf(HeaderInterface::class, $receivedHeader);
        static::assertInstanceOf(Received::class, $receivedHeader);
    }

    #[Test]
    public function getFieldNameReturnsHeaderName(): void
    {
        $receivedHeader = new Header\Received();
        static::assertSame('Received', $receivedHeader->getFieldName());
    }

    #[Test]
    public function receivedGetFieldValueReturnsProperValue(): void
    {
        $receivedHeader = new Header\Received(
            'from mail.example.com by mx.example.org; Mon, 1 Jan 2024 00:00:00 +0000',
        );
        static::assertSame(
            'from mail.example.com by mx.example.org; Mon, 1 Jan 2024 00:00:00 +0000',
            $receivedHeader->getFieldValue(),
        );
    }

    #[Test]
    public function receivedToStringReturnsHeaderFormattedString(): void
    {
        $receivedHeader = new Header\Received(
            'from mail.example.com by mx.example.org; Mon, 1 Jan 2024 00:00:00 +0000',
        );
        static::assertSame(
            'Received: from mail.example.com by mx.example.org; Mon, 1 Jan 2024 00:00:00 +0000',
            $receivedHeader->toString(),
        );
    }

    /** Implementation specific tests here */
    public static function headerLines(): array
    {
        return [
            'newline'    => ["Received: xx\nx"],
            'cr-lf'      => ["Received: xxx\r\n"],
            'cr-lf-fold' => ["Received: xxx\r\n\r\n zzz"],
            'cr-lf-x2'   => ["Received: xx\r\n\r\nx"],
            'multiline'  => ["Received: x\r\nx\r\nx"],
        ];
    }

    #[Test]
    #[DataProvider('headerLines')]
    #[Group('ZF2015-04')]
    public function raisesExceptionViaFromStringOnDetectionOfCrlfInjection(string $header): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $received = Header\Received::fromString($header);
    }

    public static function invalidValues(): array
    {
        return [
            'newline'   => ["xx\nx"],
            'cr-lf'     => ["xxx\r\n"],
            'cr-lf-wsp' => ["xx\r\n\r\nx"],
            'multiline' => ["x\r\nx\r\nx"],
        ];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    #[Group('ZF2015-04')]
    public function constructorRaisesExceptionOnValueWithCRLFInjectionAttempt(string $value): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        new Header\Received($value);
    }

    #[Test]
    public function fromStringRaisesExceptionOnInvalidHeader(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for Received string');
        Header\Received::fromString('Foo: bar');
    }

    #[Test]
    public function defaultEncoding(): void
    {
        $header = Header\Received::fromString('Received: test');
        static::assertSame('ASCII', $header->getEncoding());
    }

    #[Test]
    public function setEncodingHasNoEffect(): void
    {
        $header = Header\Received::fromString('Received: test');
        $header->setEncoding('UTF-8');
        static::assertSame('ASCII', $header->getEncoding());
    }

    #[Test]
    public function rendersHeaderLine(): void
    {
        $header = new Header\Received('test');
        static::assertSame('Received: test', $header->toString());
    }

    #[Test]
    public function toStringMultipleHeaders(): void
    {
        $header = new Header\Received('test');
        static::assertSame('Received: test', $header->toStringMultipleHeaders([]));

        $header2 = new Header\Received('test2');
        static::assertSame(
            "Received: test\r\nReceived: test2",
            $header->toStringMultipleHeaders([$header2]),
        );

        $header3 = new Header\Received('test3');
        static::assertSame(
            "Received: test\r\nReceived: test2\r\nReceived: test3",
            $header->toStringMultipleHeaders([$header2, $header3]),
        );
    }

    #[Test]
    public function toStringMultipleHeadersThrows(): void
    {
        $header = new Header\Received('test');
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('can only accept an array of Received headers');
        $header->toStringMultipleHeaders([null]);
    }
}
