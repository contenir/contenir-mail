<?php

namespace Contenir\Mail\Tests\Unit\Header;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Contenir\Mail\Header;
use Contenir\Mail\Header\Exception;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Contenir\Mail\Header\Date::class)]
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

    #[DataProvider('headerLines')]
    #[Group('ZF2015-04')]
    public function testFromStringRaisesExceptionOnCrlfInjectionAttempt(string $header): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        Header\Date::fromString($header);
    }

    #[Group('ZF2015-04')]
    public function testPreventsCRLFInjectionViaConstructor(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $address = new Header\Date("This\ris\r\na\nCRLF Attack");
    }

    public function testFromStringRaisesExceptionOnInvalidHeader(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for Date string');
        Header\Date::fromString('Foo: bar');
    }

    public function testDefaultEncoding(): void
    {
        $header = new Header\Date('today');
        $this->assertSame('ASCII', $header->getEncoding());
    }

    public function testSetEncodingHasNoEffect(): void
    {
        $header = new Header\Date('today');
        $header->setEncoding('UTF-8');
        $this->assertSame('ASCII', $header->getEncoding());
    }

    public function testToString(): void
    {
        $header = new Header\Date('today');
        $this->assertEquals('Date: today', $header->toString());
    }
}
