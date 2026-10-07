<?php

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Address;
use Contenir\Mail\Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Contenir\Mail\Address::class)]
class AddressTest extends TestCase
{
    #[Test]
    public function doesNotRequireNameForInstantiation(): void
    {
        $address = new Address('test@example.com');
        static::assertSame('test@example.com', $address->getEmail());
        static::assertNull($address->getName());
    }

    #[Test]
    public function acceptsNameViaConstructor(): void
    {
        $address = new Address('test@example.com', 'Example Test');
        static::assertSame('test@example.com', $address->getEmail());
        static::assertSame('Example Test', $address->getName());
    }

    #[Test]
    public function toStringCreatesStringRepresentation(): void
    {
        $address = new Address('test@example.com', 'Example Test');
        static::assertSame('Example Test <test@example.com>', $address->toString());
    }

    /**
     * @param string $email
     * @param null|string $name
     */
    #[Test]
    #[DataProvider('invalidSenderDataProvider')]
    #[Group('ZF2015-04')]
    public function setAddressInvalidAddressObject($email, $name): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        new Address($email, $name);
    }

    public static function invalidSenderDataProvider(): array
    {
        return [
            // Description => [sender address, sender name],
            'Empty'     => ['', null],
            'any ASCII' => ['azAZ09-_', null],
            'any UTF-8' => ['ázÁZ09-_', null],

            // CRLF @group ZF2015-04 cases
            ["foo@bar\n", null],
            ["foo@bar\r", null],
            ["foo@bar\r\n", null],
            ['foo@bar', "\r"],
            ['foo@bar', "\n"],
            ['foo@bar', "\r\n"],
            ['foo@bar', "foo\r\nevilBody"],
            ['foo@bar', "\r\nevilBody"],
        ];
    }

    /**
     * @param string $email
     * @param null|string $name
     */
    #[Test]
    #[DataProvider('validSenderDataProvider')]
    public function setAddressValidAddressObject($email, $name): void
    {
        $address = new Address($email, $name);
        static::assertInstanceOf(Address::class, $address);
    }

    public static function validSenderDataProvider(): array
    {
        return [
            // Description => [sender address, sender name],
            'german IDN' => ['oau@ä-umlaut.de', null],
        ];
    }
}
