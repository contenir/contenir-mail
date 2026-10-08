<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Storage\Exception\OutOfBoundsException;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\Imap;
use Contenir\Mail\Storage\Message;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function strlen;

/**
 * Paging through a large folder: the server sorts, and a page of messages
 * comes in one FETCH (laminas/laminas-mail#177, #216; contenir/contenir-mail#21).
 */
#[CoversClass(Imap::class)]
#[Group('unit')]
final class ImapPagingTest extends TestCase
{
    /**
     * A server with INBOX selected; the next command is tagged TAG2.
     */
    private static function server(): InMemoryConnection
    {
        return ScriptedServer::imapGreeting()
            ->expect("TAG1 SELECT \"INBOX\"\r\n")
            ->reply("* 15000 EXISTS\r\nTAG1 OK [READ-WRITE]\r\n");
    }

    /**
     * The same server, asked for its capabilities and offering SORT; the next command is tagged TAG3.
     */
    private static function sortingServer(): InMemoryConnection
    {
        return self::server()
            ->expect("TAG2 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 SORT\r\nTAG2 OK\r\n");
    }

    private static function mailbox(InMemoryConnection $server): Imap
    {
        return new Imap(ScriptedServer::imap($server));
    }

    private static function fetched(int $number, string $subject, string $flags): string
    {
        $header = "Subject: {$subject}\r\n\r\n";

        return "* {$number} FETCH (FLAGS ({$flags}) RFC822.HEADER {" . strlen($header) . "}\r\n{$header})\r\n";
    }

    #[Test]
    public function readsAPageOfTheNewestMessagesWithOneFetch(): void
    {
        $server = self::sortingServer()
            ->expect("TAG3 SORT (REVERSE ARRIVAL) UTF-8 ALL\r\n")
            ->reply("* SORT 15000 14999 14998 3\r\nTAG3 OK\r\n")
            ->expect("TAG4 FETCH 15000,14999,14998 (FLAGS RFC822.HEADER)\r\n")
            ->reply(
                self::fetched(14_998, 'Third', '')
                    . self::fetched(15_000, 'First', '\Seen')
                    . self::fetched(14_999, 'Second', '\Seen \Flagged')
                    . "TAG4 OK\r\n",
            )
            ->hangUp();
        $mailbox = self::mailbox($server);

        $newest = $mailbox->sortMessages('REVERSE ARRIVAL');
        $page   = $mailbox->getMessages(...array_slice($newest, offset: 0, length: 3));

        static::assertSame(
            [
                [15_000, 14_999, 14_998, 3],
                [15_000, 14_999, 14_998],
                ['First', 'Second', 'Third'],
                [true, true, false],
                true,
            ],
            [
                $newest,
                array_keys($page),
                array_map(static fn(Message $message): ?string => $message->getSubject(), array_values($page)),
                array_map(static fn(Message $message): bool => $message->hasFlag(Flag::Seen), array_values($page)),
                $server->isScriptComplete(),
            ],
        );
    }

    #[Test]
    public function fetchesABodyOnlyWhenItIsRead(): void
    {
        $server = self::server()
            ->expect("TAG2 FETCH 7 (FLAGS RFC822.HEADER)\r\n")
            ->reply(self::fetched(7, 'Hi', '') . "TAG2 OK\r\n")
            ->expect("TAG3 FETCH 7 (RFC822.TEXT)\r\n")
            ->reply("* 7 FETCH (RFC822.TEXT {5}\r\nHello)\r\nTAG3 OK\r\n")
            ->hangUp();
        $message = self::mailbox($server)->getMessages(7)[7] ?? null;

        static::assertSame('Hello', $message?->getContent());
    }

    #[Test]
    public function leavesOutANumberTheServerSentNoHeadersFor(): void
    {
        $server = self::server()
            ->expect("TAG2 FETCH 99,1 (FLAGS RFC822.HEADER)\r\n")
            ->reply("* 99 FETCH (UID 5)\r\n" . self::fetched(1, 'Only', '') . "TAG2 OK\r\n")
            ->hangUp();

        static::assertSame([1], array_keys(self::mailbox($server)->getMessages(99, 1)));
    }

    #[Test]
    public function asksForNothingWithoutNumbers(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 SELECT \"INBOX\"\r\n")
            ->reply("TAG1 OK\r\n")
            ->hangUp();

        static::assertSame([], self::mailbox($server)->getMessages());
    }

    #[Test]
    public function refusesANumberBelowOne(): void
    {
        $mailbox = self::mailbox(
            ScriptedServer::imapGreeting()->expect("TAG1 SELECT \"INBOX\"\r\n")->reply("TAG1 OK\r\n")->hangUp(),
        );

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('There is no message 0');

        $mailbox->getMessages(1, 0);
    }

    #[Test]
    public function reportsARefusedSort(): void
    {
        $server = self::sortingServer()
            ->expect("TAG3 SORT (DATE) UTF-8 ALL\r\n")
            ->reply("TAG3 NO Not now\r\n")
            ->hangUp();
        $mailbox = self::mailbox($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server refused the sort');

        $mailbox->sortMessages('DATE');
    }

    #[Test]
    public function refusesToSortWithoutAFolder(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 SELECT \"INBOX\"\r\n")
            ->reply("TAG1 OK\r\n")
            ->expect("TAG2 LOGOUT\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();
        $mailbox = self::mailbox($server);
        $mailbox->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No folder is selected');

        $mailbox->sortMessages('DATE');
    }
}
