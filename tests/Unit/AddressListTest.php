<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use ArrayIterator;
use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Header\AddressListCodec;
use Contenir\Mail\Header\To;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function array_values;
use function count;
use function iterator_to_array;

#[CoversClass(AddressList::class)]
#[CoversClass(AddressListCodec::class)]
#[Group('unit')]
final class AddressListTest extends TestCase
{
    #[Test]
    public function isEmptyByDefault(): void
    {
        static::assertTrue((new AddressList())->isEmpty());
    }

    #[Test]
    public function countsNoAddressesByDefault(): void
    {
        static::assertCount(0, new AddressList());
    }

    #[Test]
    public function isNotEmptyWithAnAddress(): void
    {
        static::assertFalse((new AddressList(new Address('test@example.com')))->isEmpty());
    }

    #[Test]
    public function countsEachAddress(): void
    {
        $list = new AddressList(new Address('one@example.com'), new Address('two@example.com'));

        static::assertSame(2, count($list));
    }

    #[Test]
    public function iteratesAddressesInOrder(): void
    {
        $list = new AddressList(new Address('one@example.com'), new Address('two@example.com'));

        static::assertSame(['one@example.com', 'two@example.com'], self::emails(iterator_to_array($list)));
    }

    #[Test]
    public function iteratesWithSequentialKeys(): void
    {
        $list = new AddressList(new Address('one@example.com'), new Address('two@example.com'));

        static::assertSame([0, 1], array_keys(iterator_to_array($list->getIterator())));
    }

    #[Test]
    public function returnsArrayIterator(): void
    {
        static::assertInstanceOf(ArrayIterator::class, (new AddressList())->getIterator());
    }

    #[Test]
    public function toArrayListsAddressesInOrder(): void
    {
        $one  = new Address('one@example.com');
        $two  = new Address('two@example.com');
        $list = new AddressList($one, $two);

        static::assertSame([$one, $two], $list->toArray());
    }

    #[Test]
    public function doesNotStoreDuplicatesAndFirstWins(): void
    {
        $first = new Address('test@example.com');
        $list  = new AddressList($first, new Address('test@example.com', 'Example Test'));

        static::assertSame([$first], $list->toArray());
    }

    #[Test]
    public function comparesEmailsCaseInsensitivelyForDuplicates(): void
    {
        $first = new Address('Test@Example.com');
        $list  = new AddressList($first, new Address('test@example.COM'));

        static::assertSame([$first], $list->toArray());
    }

    #[Test]
    public function hasReturnsFalseWhenAddressNotInList(): void
    {
        static::assertFalse((new AddressList())->has('foo@example.com'));
    }

    #[Test]
    public function hasReturnsTrueWhenAddressInList(): void
    {
        static::assertTrue((new AddressList(new Address('test@example.com')))->has('test@example.com'));
    }

    #[Test]
    public function hasComparesCaseInsensitively(): void
    {
        static::assertTrue((new AddressList(new Address('test@example.com')))->has('TEST@Example.com'));
    }

    #[Test]
    public function getReturnsNullWhenEmailNotFound(): void
    {
        static::assertNull((new AddressList())->get('foo@example.com'));
    }

    #[Test]
    public function getReturnsAddressWhenEmailFound(): void
    {
        $address = new Address('test@example.com', 'Example Test');

        static::assertSame($address, (new AddressList($address))->get('test@example.com'));
    }

    #[Test]
    public function getComparesCaseInsensitively(): void
    {
        $address = new Address('test@example.com');

        static::assertSame($address, (new AddressList($address))->get('Test@Example.COM'));
    }

    #[Test]
    public function firstReturnsNullWhenEmpty(): void
    {
        static::assertNull((new AddressList())->first());
    }

    #[Test]
    public function firstReturnsEarliestAddress(): void
    {
        $one = new Address('one@example.com');

        static::assertSame($one, (new AddressList($one, new Address('two@example.com')))->first());
    }

    #[Test]
    public function withAddsEmailAndName(): void
    {
        $address = (new AddressList())->with('test@example.com', 'Example Test')->get('test@example.com');

        static::assertSame(['test@example.com', 'Example Test'], [$address?->getEmail(), $address?->getName()]);
    }

    #[Test]
    public function withAddsAddressObject(): void
    {
        $address = new Address('test@example.com');

        static::assertSame(
            [$address],
            (new AddressList())->with($address)
                ->toArray(),
        );
    }

    #[Test]
    public function withAppendsAfterExistingAddresses(): void
    {
        $list = (new AddressList(new Address('one@example.com')))->with('two@example.com');

        static::assertSame(['one@example.com', 'two@example.com'], self::emails($list->toArray()));
    }

    #[Test]
    public function withLeavesOriginalUnchanged(): void
    {
        $list = new AddressList();
        $list->with('test@example.com');

        static::assertTrue($list->isEmpty());
    }

    #[Test]
    public function withIgnoresAddressAlreadyInList(): void
    {
        $first = new Address('test@example.com');
        $list  = (new AddressList($first))->with('TEST@example.com', 'Other Name');

        static::assertSame([$first], $list->toArray());
    }

    #[Test]
    public function withRejectsInvalidEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Email must be a valid email address');

        (new AddressList())->with('');
    }

    #[Test]
    public function withListMergesTwoLists(): void
    {
        $list = (new AddressList(new Address('one@example.net')))->withList(
            new AddressList(new Address('two@example.org')),
        );

        static::assertSame(['one@example.net', 'two@example.org'], self::emails($list->toArray()));
    }

    #[Test]
    public function withListKeepsExistingAddressOnDuplicate(): void
    {
        $first = new Address('test@example.com');
        $list  = (new AddressList($first))->withList(new AddressList(new Address('test@example.com', 'Other')));

        static::assertSame([$first], $list->toArray());
    }

    #[Test]
    public function withListLeavesOriginalUnchanged(): void
    {
        $list = new AddressList(new Address('one@example.net'));
        $list->withList(new AddressList(new Address('two@example.org')));

        static::assertSame(['one@example.net'], self::emails($list->toArray()));
    }

    #[Test]
    public function withoutRemovesAddress(): void
    {
        $list = (new AddressList(new Address('test@example.com')))->without('test@example.com');

        static::assertTrue($list->isEmpty());
    }

    #[Test]
    public function withoutComparesCaseInsensitively(): void
    {
        $list = (new AddressList(new Address('test@example.com')))->without('Test@Example.COM');

        static::assertTrue($list->isEmpty());
    }

    #[Test]
    public function withoutIgnoresAddressNotInList(): void
    {
        $address = new Address('test@example.com');
        $list    = (new AddressList($address))->without('other@example.com');

        static::assertSame([$address], $list->toArray());
    }

    #[Test]
    public function withoutLeavesOriginalUnchanged(): void
    {
        $list = new AddressList(new Address('test@example.com'));
        $list->without('test@example.com');

        static::assertTrue($list->has('test@example.com'));
    }

    /**
     * @param iterable<int|string, Address|string|null> $input
     * @param list<array{string, ?string}> $expected
     */
    #[DataProvider('fromIterableProvider')]
    #[Test]
    public function buildsListFromIterable(iterable $input, array $expected): void
    {
        $actual = array_map(
            static fn(Address $address): array => [$address->getEmail(), $address->getName()],
            AddressList::fromIterable($input)->toArray(),
        );

        static::assertSame($expected, $actual);
    }

    #[Test]
    public function fromIterableKeepsAddressObject(): void
    {
        $address = new Address('test@example.com', 'Example Test');

        static::assertSame([$address], AddressList::fromIterable([$address])->toArray());
    }

    #[Test]
    public function fromIterableRejectsEmptyEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An address list entry is empty');

        AddressList::fromIterable([null]);
    }

    #[Test]
    public function fromIterableRejectsInvalidAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The input is not a valid email address');

        AddressList::fromIterable(['not-an-address']);
    }

    /**
     * Parentheses in a quoted string are text, not a comment (laminas/laminas-mail#70).
     */
    #[Test]
    public function keepsParenthesesInQuotedNameAsText(): void
    {
        $address = To::fromString('To:"Supports (E-mail)" <support@example.org>')
            ->getAddressList()
            ->get('support@example.org');

        static::assertSame(
            ['support@example.org', 'Supports (E-mail)', null],
            [$address?->getEmail(), $address?->getName(), $address?->getComment()],
        );
    }

    #[Test]
    public function readsCommentAfterQuotedNameHoldingEscapedQuote(): void
    {
        $address = To::fromString('To: "Jo \"x\" (a)" (b) <jo@example.org>')
            ->getAddressList()
            ->get('jo@example.org');

        static::assertSame(['Jo "x" (a)', 'b'], [$address?->getName(), $address?->getComment()]);
    }

    /**
     * Microsoft Outlook sends emails with semicolon separated To addresses.
     *
     * @see https://blogs.msdn.microsoft.com/oldnewthing/20150119-00/?p=44883
     */
    #[Test]
    public function parsesSemicolonSeparatedAddresses(): void
    {
        $list = To::fromString(
            'To:Some User <some.user@example.com>; uzer2.surname@example.org; asda.fasd@example.net, root@example.org',
        )->getAddressList();

        static::assertSame(
            [
                ['some.user@example.com',     'Some User'],
                ['uzer2.surname@example.org', null],
                ['asda.fasd@example.net',     null],
                ['root@example.org',          null],
            ],
            array_map(static fn(Address $address): array => [
                $address->getEmail(),
                $address->getName(),
            ], $list->toArray()),
        );
    }

    /**
     * When the name is quoted, a ' inside it is part of the name, not a terminator.
     */
    #[Test]
    public function keepsApostropheInQuotedName(): void
    {
        $list = To::fromString('To:"Bob O\'Reilly" <bob@example.com>,blah@example.com')->getAddressList();

        static::assertSame(
            [
                ['bob@example.com',  "Bob O'Reilly"],
                ['blah@example.com', null],
            ],
            array_map(static fn(Address $address): array => [
                $address->getEmail(),
                $address->getName(),
            ], $list->toArray()),
        );
    }

    /**
     * @return array<string, array{iterable<int|string, Address|string|null>, list<array{string, ?string}>}>
     */
    public static function fromIterableProvider(): array
    {
        return [
            'empty'                  => [[], []],
            'bare address strings'   => [
                ['one@example.com', 'two@example.com'],
                [
                    ['one@example.com', null],
                    ['two@example.com', null],
                ],
            ],
            'named address string'   => [['Jo <jo@example.com>'], [['jo@example.com', 'Jo']]],
            'email and name pairs'   => [['jo@example.com' => 'Jo'], [['jo@example.com', 'Jo']]],
            'email with null name'   => [['jo@example.com' => null], [['jo@example.com', null]]],
            'mixed forms'            => [
                [
                    'test@example.com',
                    'list@example.com' => 'Example List',
                    new Address('announce@example.com', 'Announce List'),
                ],
                [
                    ['test@example.com',     null],
                    ['list@example.com',     'Example List'],
                    ['announce@example.com', 'Announce List'],
                ],
            ],
            'duplicates, first wins' => [
                ['test@example.com', new Address('TEST@example.com', 'Example Test')],
                [['test@example.com', null]],
            ],
            'generator'              => [
                (static function (): iterable {
                    yield 'one@example.com';
                    yield 'two@example.com' => 'Two';
                })(),
                [
                    ['one@example.com', null],
                    ['two@example.com', 'Two'],
                ],
            ],
        ];
    }

    /**
     * @param array<array-key, Address> $addresses
     * @return list<string>
     */
    private static function emails(array $addresses): array
    {
        return array_values(array_map(static fn(Address $address): string => $address->getEmail(), $addresses));
    }
}
