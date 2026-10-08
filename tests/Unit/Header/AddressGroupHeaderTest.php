<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Address;
use Contenir\Mail\AddressGroup;
use Contenir\Mail\AddressList;
use Contenir\Mail\Header\AbstractAddressList;
use Contenir\Mail\Header\AddressEncoder;
use Contenir\Mail\Header\AddressListCodec;
use Contenir\Mail\Header\Cc;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Header\To;
use Contenir\Mail\Headers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Address groups in address-list headers (RFC 5322, section 3.4; RFC 6854).
 */
#[CoversClass(AbstractAddressList::class)]
#[CoversClass(AddressListCodec::class)]
#[CoversClass(AddressEncoder::class)]
#[Group('unit')]
final class AddressGroupHeaderTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function entries(AbstractAddressList $header): array
    {
        return array_map(
            static fn(AddressGroup $group): string => $group->toString(),
            $header->getGroups(),
        );
    }

    #[Test]
    #[DataProvider('readProvider')]
    public function readsGroupNamesAndMembers(string $value, string $expected): void
    {
        static::assertSame($expected, To::fromString("To: {$value}")->getFieldValue());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function readProvider(): array
    {
        return [
            'empty group'                => ['undisclosed-recipients:;', 'undisclosed-recipients:;'],
            'group with members'         => ['Team: a@x.example, b@x.example;', 'Team: a@x.example, b@x.example;'],
            'addresses around a group'   => [
                'a@x.example, Team: b@x.example;, c@x.example',
                'a@x.example, Team: b@x.example;, c@x.example',
            ],
            'quoted member with ; in it' => ['Team: "Jo; X" <c@x.example>;', 'Team: "Jo; X" <c@x.example>;'],
            'encoded group name'         => ['=?UTF-8?Q?=C3=89quipe?=: e@x.example;', 'Équipe: e@x.example;'],
            'two groups'                 => ['A: a@x.example;, B:;', 'A: a@x.example;, B:;'],
        ];
    }

    #[Test]
    public function namesEveryAddressIncludingGroupMembersInOrder(): void
    {
        static::assertSame(
            ['a@x.example', 'b@x.example', 'c@x.example'],
            array_map(
                static fn(Address $address): string => $address->getEmail(),
                To::fromString('To: a@x.example, Team: b@x.example;, c@x.example')->getAddressList()->toArray(),
            ),
        );
    }

    #[Test]
    public function listsItsGroups(): void
    {
        static::assertSame(
            ['A: a@x.example;', 'B:;'],
            self::entries(To::fromString('To: x@x.example, A: a@x.example;, B:;')),
        );
    }

    #[Test]
    public function keepsGroupWithMalformedMemberAsGenericHeader(): void
    {
        static::assertInstanceOf(GenericHeader::class, Headers::fromString("To: Team: not an address;\r\n")->get('To'));
    }

    #[Test]
    #[DataProvider('writeProvider')]
    public function writesGroups(AbstractAddressList $header, string $expected): void
    {
        static::assertSame($expected, $header->toString());
    }

    /**
     * @return array<string, array{AbstractAddressList, string}>
     */
    public static function writeProvider(): array
    {
        return [
            'empty group'        => [
                new To(new AddressGroup('undisclosed-recipients')),
                'To: undisclosed-recipients:;',
            ],
            'group with members' => [
                new Cc(
                    new AddressGroup('Team', new AddressList(new Address('a@x.example'), new Address('b@x.example'))),
                ),
                "Cc: Team: a@x.example,\r\n b@x.example;",
            ],
            'name with specials' => [new To(new AddressGroup('Team: A')), 'To: "Team: A":;'],
            'name not ASCII'     => [new To(new AddressGroup('Équipe')), 'To: =?UTF-8?Q?=C3=89quipe?=:;'],
            'address then group' => [
                new To(new Address('x@x.example'), new AddressGroup('B')),
                "To: x@x.example,\r\n B:;",
            ],
        ];
    }

    #[Test]
    public function readsWhatItWrites(): void
    {
        $header = new To(
            new Address('x@x.example'),
            new AddressGroup('Équipe', new AddressList(new Address('a@x.example', 'Jo, A'))),
        );

        static::assertSame($header->getFieldValue(), To::fromString($header->toString())->getFieldValue());
    }

    #[Test]
    public function writesNothingWithoutAddressesOrGroups(): void
    {
        static::assertSame('', (new To())->toString());
    }

    #[Test]
    public function addsGroupAfterItsEntries(): void
    {
        $header = (new To(new Address('x@x.example')))->withAdded(new AddressGroup('B'));

        static::assertSame('x@x.example, B:;', $header->getFieldValue());
    }

    #[Test]
    public function addsOnlyAddressesNotAlreadyThere(): void
    {
        $header = (new To(new Address('x@x.example'), new AddressGroup('B')))->withAdded(
            new AddressList(new Address('X@x.example'), new Address('y@x.example')),
        )
            ->withAdded(new Address('z@x.example'));

        static::assertSame('x@x.example, B:;, y@x.example, z@x.example', $header->getFieldValue());
    }

    #[Test]
    public function addsAddressAlsoInAGroup(): void
    {
        $header = (new To(new AddressGroup('B', new AddressList(new Address('y@x.example')))))->withAdded(
            new Address('y@x.example'),
        );

        static::assertSame('B: y@x.example;, y@x.example', $header->getFieldValue());
    }

    #[Test]
    public function keepsGroupsWhenAddressesAreReplaced(): void
    {
        $header = (new To(new Address('x@x.example'), new AddressGroup('B')))->withAddressList(new AddressList(
            new Address('y@x.example'),
        ));

        static::assertSame('y@x.example, B:;', $header->getFieldValue());
    }
}
