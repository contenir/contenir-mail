<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Server-side SORT (RFC 5256) and counting with ESEARCH (RFC 4731), for
 * paging through large folders (contenir/contenir-mail#21).
 */
#[CoversClass(Imap::class)]
#[Group('unit')]
final class SortAndCountTest extends TestCase
{
    /**
     * A server listing $capabilities, asked for them first; the next command is tagged TAG2.
     */
    private static function server(string $capabilities): InMemoryConnection
    {
        return ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 {$capabilities}\r\nTAG1 OK\r\n");
    }

    #[Test]
    public function sortsBySeveralKeys(): void
    {
        $server = self::server('SORT')
            ->expect("TAG2 SORT (REVERSE DATE SUBJECT) UTF-8 ALL\r\n")
            ->reply("* SORT 5 3 4\r\nTAG2 OK\r\n")
            ->hangUp();

        static::assertSame(['5', '3', '4'], ScriptedServer::imap($server)->sort(['reverse date', 'SUBJECT']));
    }

    #[Test]
    public function sortsUidsMatchingASearch(): void
    {
        $server = self::server('SORT')
            ->expect("TAG2 UID SORT (DISPLAYFROM) UTF-8 UNSEEN SINCE 1-Jan-2026\r\n")
            ->reply("* OK still here\r\n* sort 12 10\r\nTAG2 OK\r\n")
            ->hangUp();

        static::assertSame(
            ['12', '10'],
            ScriptedServer::imap($server)->sort(['DISPLAYFROM'], ['UNSEEN', 'SINCE', '1-Jan-2026'], uid: true),
        );
    }

    #[Test]
    public function sortsNothingWhenNothingMatches(): void
    {
        $server = self::server('SORT')
            ->expect("TAG2 SORT (ARRIVAL) UTF-8 ALL\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();

        static::assertSame([], ScriptedServer::imap($server)->sort(['ARRIVAL']));
    }

    #[Test]
    public function reportsARefusedSort(): void
    {
        $server = self::server('SORT')
            ->expect("TAG2 SORT (SIZE) UTF-8 ALL\r\n")
            ->reply("TAG2 NO [BADCHARSET] Not now\r\n")
            ->hangUp();

        static::assertFalse(ScriptedServer::imap($server)->sort(['SIZE']));
    }

    #[Test]
    public function refusesToSortWithoutSort(): void
    {
        $imap = ScriptedServer::imap(self::server('ESEARCH')->hangUp());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server does not offer SORT');

        $imap->sort(['DATE']);
    }

    #[Test]
    #[DataProvider('badKeyProvider')]
    public function refusesABadSortKey(array $keys, string $message): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->hangUp());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $imap->sort($keys);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function badKeyProvider(): array
    {
        return [
            'no key'             => [[], 'SORT needs at least one sort key'],
            'an unknown key'     => [['NAME'], '"NAME" is not an IMAP sort key'],
            'REVERSE alone'      => [['REVERSE'], '"REVERSE" is not an IMAP sort key'],
            'an injected search' => [['DATE) UTF-8 ALL'], '"DATE) UTF-8 ALL" is not an IMAP sort key'],
            'a trailing line'    => [["DATE\n"], "\"DATE\n\" is not an IMAP sort key"],
            'two spaces'         => [['REVERSE  DATE'], '"REVERSE  DATE" is not an IMAP sort key'],
        ];
    }

    #[Test]
    public function countsOnTheServerWithEsearch(): void
    {
        $server = self::server('ESEARCH')
            ->expect("TAG2 SEARCH RETURN (COUNT) UNSEEN SINCE 1-Jan-2026\r\n")
            ->reply("* OK still here\r\n* ESEARCH (TAG \"TAG2\") count 15000\r\nTAG2 OK\r\n")
            ->hangUp();

        static::assertSame(15_000, ScriptedServer::imap($server)->searchCount(['UNSEEN', 'SINCE', '1-Jan-2026']));
    }

    #[Test]
    public function countsNoneWhenEsearchGivesNoCount(): void
    {
        $server = self::server('ESEARCH')
            ->expect("TAG2 SEARCH RETURN (COUNT) ALL\r\n")
            ->reply("* ESEARCH (TAG \"TAG2\") MIN 1\r\nTAG2 OK\r\n")
            ->hangUp();

        static::assertSame(0, ScriptedServer::imap($server)->searchCount(['ALL']));
    }

    #[Test]
    public function countsTheNumbersWithoutEsearch(): void
    {
        $server = self::server('SORT')
            ->expect("TAG2 SEARCH ALL\r\n")
            ->reply("* SEARCH 1 2 3\r\nTAG2 OK\r\n")
            ->hangUp();

        static::assertSame(3, ScriptedServer::imap($server)->searchCount(['ALL']));
    }

    #[Test]
    public function countsWithEsearchOnceImap4Rev2IsEnabled(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 IMAP4rev2\r\nTAG1 OK\r\n")
            ->expect("TAG2 LOGIN \"jo\" \"secret\"\r\n")
            ->reply("TAG2 OK\r\n")
            ->expect("TAG3 ENABLE IMAP4rev2\r\n")
            ->reply("* ENABLED IMAP4rev2\r\nTAG3 OK\r\n")
            ->expect("TAG4 SEARCH RETURN (COUNT) ALL\r\n")
            ->reply("* ESEARCH (TAG \"TAG4\") COUNT 7\r\nTAG4 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        $imap->login('jo', 'secret');

        static::assertSame(7, $imap->searchCount(['ALL']));
    }

    #[Test]
    #[DataProvider('refusedCountProvider')]
    public function reportsARefusedCount(string $capabilities, string $request): void
    {
        $server = self::server($capabilities)
            ->expect($request)
            ->reply("TAG2 NO Not now\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server refused the search');

        $imap->searchCount(['ALL']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedCountProvider(): array
    {
        return [
            'with ESEARCH'    => ['ESEARCH', "TAG2 SEARCH RETURN (COUNT) ALL\r\n"],
            'without ESEARCH' => ['SORT', "TAG2 SEARCH ALL\r\n"],
        ];
    }

    #[Test]
    #[DataProvider('badCountProvider')]
    public function refusesAMalformedCount(string $count): void
    {
        $server = self::server('ESEARCH')
            ->expect("TAG2 SEARCH RETURN (COUNT) ALL\r\n")
            ->reply("* ESEARCH (TAG \"TAG2\") COUNT {$count}\r\nTAG2 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server sent a malformed search count');

        $imap->searchCount(['ALL']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badCountProvider(): array
    {
        return [
            'negative'     => ['-1'],
            'not a number' => ['many'],
            'too long'     => ['12345678901'],
            'a list'       => ['(1 2)'],
        ];
    }
}
