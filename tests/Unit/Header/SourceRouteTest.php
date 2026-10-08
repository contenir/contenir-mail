<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\AddressListCodec;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Header\To;
use Contenir\Mail\Headers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RFC 5322 section 4.4: an obsolete source route before an address is read and ignored.
 */
#[CoversClass(AddressListCodec::class)]
#[Group('unit')]
final class SourceRouteTest extends TestCase
{
    #[Test]
    #[DataProvider('routedAddressProvider')]
    public function readsAddressWithoutItsSourceRoute(string $value, string $expected): void
    {
        static::assertSame($expected, To::fromString("To: {$value}")->getFieldValue());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function routedAddressProvider(): array
    {
        return [
            'one relay'               => ['<@relay.example:jo@example.com>', 'jo@example.com'],
            'several relays'          => ['<@a.example,@b.example:jo@example.com>', 'jo@example.com'],
            'spaces around the route' => ['< @a.example , @b.example : jo@example.com>', 'jo@example.com'],
            'with a display name'     => ['Jo <@relay.example:jo@example.com>', 'Jo <jo@example.com>'],
            'beside other addresses'  => [
                '<@relay.example:jo@example.com>, sam@example.org',
                'jo@example.com, sam@example.org',
            ],
            'no route'                => ['<jo@example.com>', 'jo@example.com'],
        ];
    }

    #[Test]
    public function keepsRouteOnlyHeaderAsGenericHeader(): void
    {
        static::assertInstanceOf(
            GenericHeader::class,
            Headers::fromString("To: <@relay.example:>\r\n")->get('To'),
        );
    }
}
