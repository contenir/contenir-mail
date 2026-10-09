<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Protocol\Exception\RuntimeException as ProtocolException;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\Idle\FlagsChanged;
use Contenir\Mail\Storage\Idle\MessageCountChanged;
use Contenir\Mail\Storage\Idle\MessageExpunged;
use Contenir\Mail\Storage\Idle\RecentCountChanged;
use Contenir\Mail\Storage\Imap;
use Contenir\Mail\Storage\Message;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\TestAsset\Protocol\ScriptedServer;
use Contenir\Mail\Tests\TestAsset\SettableClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function iterator_to_array;
use function range;

/**
 * Waiting for new mail with IDLE (RFC 2177): the server's untagged responses
 * become events, and the mailbox can be used as soon as the loop ends
 * (contenir/contenir-mail#52).
 */
#[CoversClass(Imap::class)]
#[Group('unit')]
final class ImapIdleTest extends TestCase
{
    /**
     * A server with INBOX selected and offering IDLE; IDLE is tagged TAG3.
     */
    private static function server(): InMemoryConnection
    {
        return ScriptedServer::imapGreeting()
            ->expect("TAG1 SELECT \"INBOX\"\r\n")
            ->reply("* 2 EXISTS\r\nTAG1 OK [READ-WRITE]\r\n")
            ->expect("TAG2 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 IDLE\r\nTAG2 OK\r\n")
            ->expect("TAG3 IDLE\r\n");
    }

    private static function mailbox(InMemoryConnection $server): Imap
    {
        return new Imap(ScriptedServer::imap($server->hangUp()));
    }

    #[Test]
    public function yieldsNewMailWhileIdling(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n* 3 EXISTS\r\n* 1 RECENT\r\n")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("TAG3 OK\r\n");

        static::assertEquals(
            [new MessageCountChanged(3), new RecentCountChanged(1)],
            iterator_to_array(self::mailbox($server)->idle(600)),
        );
    }

    #[Test]
    public function listensForTwentyNineMinutesByDefault(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("TAG3 OK\r\n");

        iterator_to_array(self::mailbox($server)->idle(clock: new SettableClock()));

        static::assertSame([1740], $server->waits());
    }

    #[Test]
    public function listensForTheTimeoutGiven(): void
    {
        $clock  = new SettableClock();
        $server = self::server()
            ->reply("+ idling\r\n* 3 EXISTS\r\n")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("TAG3 OK\r\n");

        foreach (self::mailbox($server)->idle(60, $clock) as $event) {
            static::assertEquals(new MessageCountChanged(3), $event);
            $clock->advance(20);
        }

        static::assertSame([60, 40], $server->waits());
    }

    #[Test]
    public function yieldsRemovedMessagesAndChangedFlags(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n* 1 EXPUNGE\r\n* 1 FETCH (FLAGS (\\Seen \$Junk))\r\n")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("TAG3 OK\r\n");

        static::assertEquals(
            [new MessageExpunged(1), new FlagsChanged(1, [Flag::Seen, '$Junk'])],
            iterator_to_array(self::mailbox($server)->idle(600)),
        );
    }

    #[Test]
    public function skipsResponsesThatTellOfNoChange(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n* OK Still here\r\n* 3 EXISTS\r\n")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("TAG3 OK\r\n");

        static::assertEquals(
            [new MessageCountChanged(3)],
            iterator_to_array(self::mailbox($server)->idle(600), preserve_keys: false),
        );
    }

    #[Test]
    public function readsTheNewMessagesOnceTheLoopIsLeft(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n* 4 EXISTS\r\n")
            ->expect("DONE\r\n")
            ->reply("TAG3 OK\r\n")
            ->expect("TAG4 FETCH 3,4 (FLAGS RFC822.HEADER)\r\n")
            ->reply(
                "* 3 FETCH (FLAGS () RFC822.HEADER {15}\r\nSubject: Hi\r\n\r\n)\r\n"
                    . "* 4 FETCH (FLAGS () RFC822.HEADER {16}\r\nSubject: Bye\r\n\r\n)\r\n"
                    . "TAG4 OK\r\n",
            );
        $mailbox = self::mailbox($server);
        $last    = 2;
        $new     = [];

        foreach ($mailbox->idle(600) as $event) {
            if (! $event instanceof MessageCountChanged || $event->count <= $last) {
                continue;
            }

            $new = $mailbox->getMessages(...range($last + 1, $event->count));

            break;
        }

        static::assertSame(
            [[3, 4], 'Bye', true],
            [
                array_keys($new),
                ($new[4] ?? null) instanceof Message ? $new[4]->getSubject() : null,
                $server->isScriptComplete(),
            ],
        );
    }

    /**
     * @mago-expect lint:loop-does-not-iterate Leaving the loop at once is the behaviour under test.
     */
    #[Test]
    public function endsIdleWhenTheCallerStopsListening(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n* 3 EXISTS\r\n")
            ->expect("DONE\r\n")
            ->reply("TAG3 OK\r\n")
            ->expect("TAG4 NOOP\r\n")
            ->reply("TAG4 OK\r\n");
        $mailbox = self::mailbox($server);
        $first   = null;

        foreach ($mailbox->idle(600) as $event) {
            $first = $event;

            break;
        }

        $mailbox->noop();

        static::assertEquals([new MessageCountChanged(3), true], [$first, $server->isScriptComplete()]);
    }

    #[Test]
    public function failsWhenTheServerSaysBye(): void
    {
        $server  = self::server()->reply("+ idling\r\n* BYE Shutting down\r\n");
        $mailbox = self::mailbox($server);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('The server closed the connection: Shutting down');

        iterator_to_array($mailbox->idle(600));
    }

    #[Test]
    public function needsASelectedFolder(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 SELECT \"INBOX\"\r\n")
            ->reply("TAG1 OK\r\n")
            ->expect("TAG2 SELECT \"Gone\"\r\n")
            ->reply("TAG2 NO No such folder\r\n");
        $mailbox = self::mailbox($server);
        try {
            $mailbox->selectFolder('Gone');
        } catch (RuntimeException) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('No folder is selected');

            $mailbox->idle();
        }
    }
}
