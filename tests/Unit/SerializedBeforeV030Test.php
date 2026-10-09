<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Address;
use Contenir\Mail\Header\AbstractAddressList;
use Contenir\Mail\Header\To;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function strlen;
use function unserialize;

/**
 * Messages serialized by 0.2.x, such as those waiting in a queue during an upgrade,
 * lack the properties 0.3.0 added and still work once unserialized.
 */
#[CoversClass(Address::class)]
#[CoversClass(AbstractAddressList::class)]
#[Group('unit')]
final class SerializedBeforeV030Test extends TestCase
{
    /**
     * An address as 0.2.x serialized it: email, name and comment, without strictness.
     */
    private static function address(string $email): string
    {
        $property = static fn(string $name): string => (
            's:'
            . strlen("\0Contenir\\Mail\\Address\0{$name}")
            . ":\"\0Contenir\\Mail\\Address\0{$name}\";"
        );

        return (
            'O:21:"Contenir\Mail\Address":3:{'
                . $property('email')
                . 's:'
                . strlen($email)
                . ":\"{$email}\";"
                . $property('name')
                . 'N;'
                . $property('comment')
                . 'N;}'
        );
    }

    /**
     * A To header as 0.2.x serialized it: its entries, without the set of their addresses.
     */
    private static function to(string ...$emails): string
    {
        $entries = '';
        foreach ($emails as $index => $email) {
            $entries .= "i:{$index};" . self::address($email);
        }

        $name = "\0Contenir\\Mail\\Header\\AbstractAddressList\0entries";

        return (
            'O:23:"Contenir\Mail\Header\To":1:{s:'
                . strlen($name)
                . ":\"{$name}\";a:"
                . count($emails)
                . ":{{$entries}}}"
        );
    }

    #[Test]
    public function treatsAnOlderAddressAsStrict(): void
    {
        $address = unserialize(self::address('jo@example.com'), ['allowed_classes' => [Address::class]]);

        static::assertInstanceOf(Address::class, $address);
        static::assertTrue($address->isStrict());
    }

    #[Test]
    public function addsToAnOlderHeaderWithoutRepeatingAnAddress(): void
    {
        $header = unserialize(
            self::to('jo@example.com', 'sam@example.com'),
            ['allowed_classes' => [To::class, Address::class]],
        );
        static::assertInstanceOf(To::class, $header);

        $added = $header->withAdded(new Address('JO@example.com'))->withAdded(new Address('kim@example.com'));

        static::assertSame(
            ['jo@example.com', 'sam@example.com', 'kim@example.com'],
            array_map(static fn(Address $address): string => $address->getEmail(), $added->getAddressList()->toArray()),
        );
    }
}
