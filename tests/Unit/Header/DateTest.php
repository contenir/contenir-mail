<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header;
use Contenir\Mail\Header\Date;
use Contenir\Mail\Header\Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Date::class)]
class DateTest extends TestCase
{
    public static function headerLines(): array
    {
        return [
            'newline'   => ["Date: xxx yyy\n"],
            'cr-lf'     => ["Date: xxx yyy\r\n"],
            'cr-lf-wsp' => ["Date: xxx yyy\r\n\r\n"],
            'multiline' => ["Date: xxx\r\ny\r\nyy"],
        ];
    }

    #[Test]
    #[DataProvider('headerLines')]
    #[Group('ZF2015-04')]
    public function fromStringRaisesExceptionOnCrlfInjectionAttempt(string $header): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        Header\Date::fromString($header);
    }

    #[Test]
    #[Group('ZF2015-04')]
    public function preventsCRLFInjectionViaConstructor(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $address = new Header\Date("This\ris\r\na\nCRLF Attack");
    }

    #[Test]
    public function fromStringRaisesExceptionOnInvalidHeader(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for Date string');
        Header\Date::fromString('Foo: bar');
    }

    #[Test]
    public function defaultEncoding(): void
    {
        $header = new Header\Date('today');
        static::assertSame('ASCII', $header->getEncoding());
    }

    #[Test]
    public function setEncodingHasNoEffect(): void
    {
        $header = new Header\Date('today');
        $header->setEncoding('UTF-8');
        static::assertSame('ASCII', $header->getEncoding());
    }

    #[Test]
    public function rendersHeaderLine(): void
    {
        $header = new Header\Date('today');
        static::assertSame('Date: today', $header->toString());
    }
}
