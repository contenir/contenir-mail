<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Adding to an address list works from the addresses it already holds, keyed
 * and unique, without building the list again.
 */
#[CoversClass(AddressList::class)]
#[Group('unit')]
final class AddressListAppendTest extends TestCase
{
    /**
     * @return array<string, array{AddressList, list<string>}>
     */
    public static function appendProvider(): array
    {
        $jo  = new Address('Jo@Example.org');
        $sam = new Address('sam@example.org');

        return [
            'one at a time'          => [
                (new AddressList())->with($jo)
                    ->with($sam)
                    ->with('ann@example.org'),
                ['Jo@Example.org', 'sam@example.org', 'ann@example.org'],
            ],
            'again in other case'    => [
                (new AddressList())->with($jo)
                    ->with($sam)
                    ->with('jo@example.ORG', 'Jo'),
                ['Jo@Example.org', 'sam@example.org'],
            ],
            'list after list'        => [
                (new AddressList($jo))->withList(
                    new AddressList($sam, new Address('JO@example.org'), new Address('ann@example.org')),
                ),
                ['Jo@Example.org', 'sam@example.org', 'ann@example.org'],
            ],
            'added after removal'    => [
                (new AddressList($jo, $sam))->without('JO@example.org')
                    ->with($jo),
                ['sam@example.org', 'Jo@Example.org'],
            ],
            'list after an addition' => [
                (new AddressList())->with($sam)
                    ->withList(new AddressList($jo, $sam)),
                ['sam@example.org', 'Jo@Example.org'],
            ],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('appendProvider')]
    #[Test]
    public function addsEachAddressOnceAndInOrder(AddressList $list, array $expected): void
    {
        static::assertSame($expected, array_map(
            static fn(Address $address): string => $address->getEmail(),
            $list->toArray(),
        ));
    }

    #[Test]
    public function findsAnAddedAddressWhateverItsCase(): void
    {
        static::assertTrue((new AddressList())->with('Jo@Example.org')->has('jo@example.ORG'));
    }

    #[Test]
    public function findsNothingOfARemovedAddress(): void
    {
        static::assertNull(
            (new AddressList(new Address('jo@example.org')))->with('sam@example.org')
                ->without('JO@example.org')
                ->get('jo@example.org'),
        );
    }

    #[Test]
    public function keepsALenientAddressItAdds(): void
    {
        $lenient = new Address('jo..bloggs@example.org', strict: false);

        static::assertSame(
            $lenient,
            (new AddressList())->with($lenient)
                ->first(),
        );
    }

    #[Test]
    public function keepsALenientAddressFromAListItJoins(): void
    {
        $lenient = new Address('jo..bloggs@example.org', strict: false);

        static::assertSame(
            $lenient,
            (new AddressList())->withList(new AddressList($lenient))->get('JO..bloggs@example.org'),
        );
    }

    #[Test]
    public function leavesTheListsItJoinsAsTheyWere(): void
    {
        $first  = new AddressList(new Address('jo@example.org'));
        $second = new AddressList(new Address('sam@example.org'));
        $joined = $first->withList($second);

        static::assertSame([1, 1, 2], [$first->count(), $second->count(), $joined->count()]);
    }
}
