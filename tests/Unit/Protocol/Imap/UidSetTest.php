<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Imap\UidSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The UIDs of an RFC 4315 uid-set, bounded in value and number (contenir/contenir-mail#52).
 */
#[CoversClass(UidSet::class)]
#[Group('unit')]
final class UidSetTest extends TestCase
{
    /**
     * @param list<int>|null $uids
     */
    #[DataProvider('setProvider')]
    #[Test]
    public function expandsAUidSet(string $set, ?array $uids): void
    {
        static::assertSame($uids, UidSet::expand($set));
    }

    /**
     * @return array<string, array{string, list<int>|null}>
     */
    public static function setProvider(): array
    {
        return [
            'one UID'                   => ['7', [7]],
            'UIDs and ranges'           => ['304,319:320', [304, 319, 320]],
            'a descending range'        => ['5:3', [3, 4, 5]],
            'the largest UID'           => ['4294967295', [4_294_967_295]],
            'a UID over 32 bits'        => ['4294967296', null],
            'a range over 32 bits'      => ['4294967295:4294967296', null],
            'more than MAX_UIDS'        => ['1:1000001', null],
            'more than MAX_UIDS in all' => ['1:999999,2000000:2000002', null],
        ];
    }
}
