<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_slice;
use function count;
use function implode;
use function range;

/**
 * Sequence sets of many disjoint ranges: one pattern over the whole set ran out of
 * PCRE's stack at about 10,000 ranges, so a valid ESEARCH result was refused as
 * malformed and a long list of messages could not be fetched.
 */
#[CoversClass(Imap::class)]
#[Group('unit')]
final class LargeSequenceSetTest extends TestCase
{
    private const int RANGES = 20_000;

    #[Test]
    public function readsAnEsearchResultOfManyDisjointRanges(): void
    {
        $ids = self::search(self::ranges(self::RANGES, width: 2));

        static::assertSame(
            [self::RANGES * 2, '1', '2', '4', '59998', '59999'],
            [count($ids), $ids[0] ?? null, $ids[1] ?? null, $ids[2] ?? null, ...array_slice($ids, offset: -2)],
        );
    }

    #[DataProvider('badSetProvider')]
    #[Test]
    public function refusesABadEsearchResultOfManyRanges(string $set, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        self::search($set);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badSetProvider(): array
    {
        $ranges = self::ranges(self::RANGES, width: 2);

        return [
            'more ids than the limit'  => [
                self::ranges(self::RANGES, width: 51),
                'The server sent more than 1000000 search results',
            ],
            'a bad range at the end'   => ["{$ranges},0", 'The server sent a malformed search result'],
            'a bad range at the start' => ["0,{$ranges}", 'The server sent a malformed search result'],
            'an empty range'           => ["{$ranges},,1", 'The server sent a malformed search result'],
        ];
    }

    #[Test]
    public function copiesASetOfManyDisjointMessages(): void
    {
        $set  = implode(',', range(1, self::RANGES * 2, step: 2));
        $imap = ScriptedServer::imap(
            ScriptedServer::imapGreeting()->expect("TAG1 COPY {$set} \"Archive\"\r\n")->reply("TAG1 OK\r\n")->hangUp(),
        );

        static::assertTrue($imap->copy('Archive', $set));
    }

    #[DataProvider('badSequenceProvider')]
    #[Test]
    public function refusesASetOfManyMessagesWithOneOutsideTheGrammar(string $set): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->hangUp());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a valid message sequence set');

        $imap->copy('Archive', $set);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badSequenceProvider(): array
    {
        $set = implode(',', range(1, self::RANGES * 2, step: 2));

        return [
            'zero at the end'   => ["{$set},0"],
            'zero at the start' => ["0,{$set}"],
            'an empty part'     => ["{$set},,1"],
        ];
    }

    /**
     * $count ranges of $width ids each, one id apart: "1:2,4:5,..." for a width of 2.
     */
    private static function ranges(int $count, int $width): string
    {
        return implode(',', array_map(
            static fn(int $start): string => $start . ':' . ($start + $width - 1),
            range(1, (($count - 1) * ($width + 1)) + 1, $width + 1),
        ));
    }

    /**
     * @return list<string>
     */
    private static function search(string $set): array
    {
        $imap = ScriptedServer::imap(
            ScriptedServer::imapGreeting()
                ->expect("TAG1 SEARCH ALL\r\n")
                ->reply("* ESEARCH (TAG \"TAG1\") ALL {$set}\r\nTAG1 OK\r\n")
                ->hangUp(),
        );

        $ids = $imap->search(criteria: ['ALL']);

        return false === $ids ? [] : $ids;
    }
}
