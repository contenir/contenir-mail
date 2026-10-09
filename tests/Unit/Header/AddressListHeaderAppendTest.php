<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Address;
use Contenir\Mail\AddressGroup;
use Contenir\Mail\AddressList;
use Contenir\Mail\Header\AbstractAddressList;
use Contenir\Mail\Header\Bcc;
use Contenir\Mail\Header\To;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An address-list header remembers the addresses it holds outside groups, so
 * adding one does not go over the others, and still never adds one twice.
 */
#[CoversClass(AbstractAddressList::class)]
#[Group('unit')]
final class AddressListHeaderAppendTest extends TestCase
{
    /**
     * @return array<string, array{AbstractAddressList, string}>
     */
    public static function appendProvider(): array
    {
        $jo  = new Address('jo@example.org');
        $sam = new Address('sam@example.org');

        return [
            'same address again'                 => [(new Bcc($jo))->withAdded($jo), 'jo@example.org'],
            'same address in other case'         => [
                (new Bcc($jo))->withAdded(new Address('JO@Example.ORG')),
                'jo@example.org',
            ],
            'address from an earlier addition'   => [
                (new Bcc())->withAdded($jo)
                    ->withAdded($sam)
                    ->withAdded(new AddressList(new Address('Jo@example.org'))),
                'jo@example.org, sam@example.org',
            ],
            'address read from the header line'  => [
                Bcc::fromString('Bcc: Jo@Example.org')->withAdded($jo),
                'Jo@Example.org',
            ],
            'address added after a group'        => [
                (new To($jo))->withAdded(new AddressGroup('Team'))
                    ->withAdded(new Address('JO@example.org')),
                'jo@example.org, Team:;',
            ],
            'address only in a group'            => [
                (new To(new AddressGroup('Team', new AddressList($jo))))->withAdded($jo),
                'Team: jo@example.org;, jo@example.org',
            ],
            'list with new and known addresses'  => [
                (new To($jo))->withAdded(new AddressList($sam, new Address('JO@example.org'))),
                'jo@example.org, sam@example.org',
            ],
            'group then address after addresses' => [
                (new To($jo))->withAdded(new AddressGroup('Team'))
                    ->withAdded($sam),
                'jo@example.org, Team:;, sam@example.org',
            ],
        ];
    }

    #[DataProvider('appendProvider')]
    #[Test]
    public function addsEachAddressOnceAndInOrder(AbstractAddressList $header, string $expected): void
    {
        static::assertSame($expected, $header->getFieldValue());
    }

    #[Test]
    public function keepsTheKindOfHeaderItAddsTo(): void
    {
        static::assertInstanceOf(Bcc::class, (new Bcc())->withAdded(new Address('jo@example.org')));
    }

    #[Test]
    public function keepsALenientAddressItAdds(): void
    {
        $lenient = new Address('jo..bloggs@example.org', strict: false);

        static::assertSame(
            $lenient,
            (new Bcc())->withAdded($lenient)
                ->getAddressList()
                ->first(),
        );
    }

    #[Test]
    public function leavesTheHeaderItAddsToAsItWas(): void
    {
        $header = new Bcc(new Address('jo@example.org'));
        $added  = $header->withAdded(new Address('sam@example.org'));

        static::assertSame(['jo@example.org', 'jo@example.org, sam@example.org'], [
            $header->getFieldValue(),
            $added->getFieldValue(),
        ]);
    }
}
