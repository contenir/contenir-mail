<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Address;
use Contenir\Mail\AddressGroup;
use Contenir\Mail\AddressList;
use Contenir\Mail\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddressGroup::class)]
#[Group('unit')]
final class AddressGroupTest extends TestCase
{
    #[Test]
    public function trimsItsName(): void
    {
        static::assertSame('Team', (new AddressGroup('  Team '))->getName());
    }

    #[Test]
    public function isEmptyByDefault(): void
    {
        static::assertTrue((new AddressGroup('undisclosed-recipients'))->getAddresses()->isEmpty());
    }

    #[Test]
    public function keepsItsAddresses(): void
    {
        $addresses = new AddressList(new Address('jo@example.org'));

        static::assertSame($addresses, (new AddressGroup('Team', $addresses))->getAddresses());
    }

    #[Test]
    #[DataProvider('displayProvider')]
    public function displaysAsGroupSyntax(AddressGroup $group, string $expected): void
    {
        static::assertSame($expected, $group->toString());
    }

    /**
     * @return array<string, array{AddressGroup, string}>
     */
    public static function displayProvider(): array
    {
        return [
            'empty'              => [new AddressGroup('undisclosed-recipients'), 'undisclosed-recipients:;'],
            'members'            => [
                new AddressGroup(
                    'Team',
                    new AddressList(new Address('jo@example.org'), new Address('sam@example.org', 'Sam')),
                ),
                'Team: jo@example.org, Sam <sam@example.org>;',
            ],
            'name with specials' => [new AddressGroup('Team: A'), '"Team: A":;'],
        ];
    }

    #[Test]
    #[DataProvider('invalidNameProvider')]
    public function refusesInvalidName(string $name, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new AddressGroup($name);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidNameProvider(): array
    {
        return [
            'empty'      => ['', 'An address group needs a name'],
            'blank'      => [" \t ", 'An address group needs a name'],
            'not UTF-8'  => ["Te\xFFam", 'An address group name must be UTF-8 text'],
            'line break' => ["Team\r\nBcc: x@example.org", 'An address group name must not contain control characters'],
            'NUL'        => ["Te\x00am", 'An address group name must not contain control characters'],
            'DEL'        => ["Te\x7Fam", 'An address group name must not contain control characters'],
            'C1 control' => ["Te\u{85}am", 'An address group name must not contain control characters'],
            'escape'     => ["Te\x1Bam", 'An address group name must not contain control characters'],
        ];
    }

    #[Test]
    public function acceptsTabInName(): void
    {
        static::assertSame("Team\tA", (new AddressGroup("Team\tA"))->getName());
    }
}
