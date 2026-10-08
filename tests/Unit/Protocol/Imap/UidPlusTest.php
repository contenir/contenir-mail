<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Imap\UidPlus;
use Contenir\Mail\Protocol\Imap\UidSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function range;

/**
 * The APPENDUID and COPYUID response codes (RFC 4315), read strictly
 * (contenir/contenir-mail#52).
 */
#[CoversClass(UidPlus::class)]
#[CoversClass(UidSet::class)]
#[Group('unit')]
final class UidPlusTest extends TestCase
{
    /**
     * @param array<mixed> $tokens
     * @param list<int> $source
     * @param list<int> $destination
     */
    #[DataProvider('codeProvider')]
    #[Test]
    public function readsTheUidsOfAResponseCode(
        array $tokens,
        int $uidValidity,
        array $source,
        array $destination,
    ): void {
        static::assertEquals(
            new UidPlus($uidValidity, $source, $destination),
            UidPlus::fromTaggedReply($tokens),
        );
    }

    /**
     * @return array<string, array{array<mixed>, int, list<int>, list<int>}>
     */
    public static function codeProvider(): array
    {
        return [
            'APPENDUID'                       => [
                ['OK', '[APPENDUID', '38505', '3955]', 'APPEND', 'completed'],
                38_505,
                [],
                [3955],
            ],
            'APPENDUID in lower case'         => [['OK', '[appenduid', '1', '2]'], 1, [], [2]],
            'APPENDUID of several messages'   => [['OK', '[APPENDUID', '7', '3955:3957]'], 7, [], [3955, 3956, 3957]],
            'COPYUID'                         => [
                ['OK', '[COPYUID', '38505', '304,319:320', '3956:3958]', 'Done'],
                38_505,
                [304, 319, 320],
                [3956, 3957, 3958],
            ],
            'COPYUID before a list'           => [['OK', '[COPYUID', '1', '2', '3]', ['x']], 1, [2], [3]],
            'a descending range'              => [['OK', '[APPENDUID', '1', '5:3]'], 1, [], [3, 4, 5]],
            'the largest UIDVALIDITY and UID' => [
                ['OK', '[APPENDUID', '4294967295', '4294967295]'],
                4_294_967_295,
                [],
                [4_294_967_295],
            ],
        ];
    }

    /**
     * @param array<mixed> $tokens
     */
    #[DataProvider('ignoredProvider')]
    #[Test]
    public function ignoresAMissingOrMalformedCode(array $tokens): void
    {
        static::assertNull(UidPlus::fromTaggedReply($tokens));
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function ignoredProvider(): array
    {
        return [
            'no code'                     => [['OK', 'APPEND', 'completed']],
            'no text'                     => [['OK']],
            'another code'                => [['OK', '[READ-WRITE]', 'done']],
            'the code after text'         => [['OK', 'done', '[APPENDUID', '1', '2]']],
            'an unclosed code'            => [['OK', '[APPENDUID', '1', '2', 'x]']],
            'a list in the code'          => [['OK', '[APPENDUID', ['1'], '2]']],
            'UIDVALIDITY zero'            => [['OK', '[APPENDUID', '0', '2]']],
            'UIDVALIDITY over 32 bits'    => [['OK', '[APPENDUID', '4294967296', '2]']],
            'UIDVALIDITY over ten digits' => [['OK', '[APPENDUID', '12345678901', '2]']],
            'UID zero'                    => [['OK', '[APPENDUID', '1', '0]']],
            'UID star'                    => [['OK', '[APPENDUID', '1', '*]']],
            'UID over 32 bits'            => [['OK', '[APPENDUID', '1', '4294967296]']],
            'a source UID over 32 bits'   => [['OK', '[COPYUID', '1', '4294967296', '2]']],
            'a trailing comma'            => [['OK', '[APPENDUID', '1', '2,]']],
            'COPYUID without a source'    => [['OK', '[COPYUID', '1', '2]']],
            'COPYUID sets of other sizes' => [['OK', '[COPYUID', '1', '2:3', '4]']],
            'more than MAX_UIDS'          => [['OK', '[APPENDUID', '1', '1:1000001]']],
            'more than MAX_UIDS in all'   => [['OK', '[APPENDUID', '1', '1:999999,2000000:2000002]']],
        ];
    }

    #[Test]
    public function readsAsManyUidsAsMaxUids(): void
    {
        static::assertSame(
            range(1, UidPlus::MAX_UIDS),
            UidPlus::fromTaggedReply(['OK', '[APPENDUID', '1', '1:1000000]'])?->destinationUids,
        );
    }

    #[DataProvider('uidProvider')]
    #[Test]
    public function givesTheUidOfASingleStoredMessage(UidPlus $uids, ?int $uid): void
    {
        static::assertSame($uid, $uids->uid());
    }

    /**
     * @return array<string, array{UidPlus, int|null}>
     */
    public static function uidProvider(): array
    {
        return [
            'one message'      => [new UidPlus(1, [], [42]), 42],
            'one copy'         => [new UidPlus(1, [7], [42]), 42],
            'several messages' => [new UidPlus(1, [7, 8], [42, 43]), null],
            'no message'       => [new UidPlus(1, [], []), null],
        ];
    }
}
