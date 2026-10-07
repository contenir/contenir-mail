<?php

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\Exception;
use Contenir\Mail\Header\GenericMultiHeader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

#[CoversClass(\Contenir\Mail\Header\GenericMultiHeader::class)]
class GenericMultiHeaderTest extends TestCase
{
    #[Test]
    public function fromStringSingle(): void
    {
        $multiHeader = GenericMultiHeader::fromString('x-custom: test');
        static::assertSame(GenericMultiHeader::class, $multiHeader::class);
    }

    #[Test]
    public function fromStringMultiple(): void
    {
        $headers = GenericMultiHeader::fromString('x-custom: foo,bar');
        static::assertSame(2, count($headers));
        foreach ($headers as $header) {
            static::assertSame(GenericMultiHeader::class, $header::class);
        }
    }

    #[Test]
    public function toStringSingle(): void
    {
        $multiHeader = new GenericMultiHeader('x-custom', 'test');

        static::assertSame('X-Custom: test', $multiHeader->toStringMultipleHeaders([]));
    }

    #[Test]
    public function toStringMultiple(): void
    {
        $multiHeader   = new GenericMultiHeader('x-custom', 'test');
        $anotherHeader = new GenericMultiHeader('x-custom', 'two');

        static::assertSame('X-Custom: test,two', $multiHeader->toStringMultipleHeaders([$anotherHeader]));
    }

    #[Test]
    public function toStringInvalid(): void
    {
        $multiHeader = new GenericMultiHeader('x-custom', 'test');

        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'This method toStringMultipleHeaders was expecting an array of headers of the same type',
        );
        $multiHeader->toStringMultipleHeaders([null]);
    }
}
