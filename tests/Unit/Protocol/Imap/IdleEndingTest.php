<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\ResponseLimits;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\TestAsset\Protocol\ScriptedServer;
use Contenir\Mail\Tests\TestAsset\SettableClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function str_repeat;

/**
 * Ending IDLE (RFC 2177): DONE and the tagged reply leave the connection
 * usable however listening stops, and refusals and BYE are reported
 * (contenir/contenir-mail#52).
 */
#[CoversClass(Imap::class)]
#[Group('unit')]
final class IdleEndingTest extends TestCase
{
    /**
     * A server offering IDLE; the IDLE command is tagged TAG2.
     */
    private static function server(): InMemoryConnection
    {
        return ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 IDLE\r\nTAG1 OK\r\n")
            ->expect("TAG2 IDLE\r\n");
    }

    /**
     * A client of the scripted server, which hangs up after its script, so LOGOUT fails quietly.
     */
    private static function imap(InMemoryConnection $server): Imap
    {
        return ScriptedServer::imap($server->hangUp());
    }

    #[Test]
    public function sendsDoneAsTheGeneratorIsDestroyed(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n* 3 EXISTS\r\n")
            ->expect("DONE\r\n")
            ->reply("* 4 EXISTS\r\nTAG2 OK\r\n");
        $imap = self::imap($server);

        $first = $imap->idle(600)->current();

        static::assertSame([['3', 'EXISTS'], true], [$first, $server->isScriptComplete()]);
    }

    #[Test]
    public function boundsTheReplyReadAsTheGeneratorIsDestroyed(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n* 3 EXISTS\r\n")
            ->expect("DONE\r\n")
            ->reply('* OK ' . str_repeat('x', times: 1009) . "\r\nTAG2 OK\r\n");
        $imap = self::imap($server);
        $imap->setResponseLimits(new ResponseLimits(
            maxLineLength: 1024,
            maxResponseSize: 1024,
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The server's response exceeds the limit of 1024 bytes");

        $imap->idle(600)->current();
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
            ->reply("* 4 EXISTS\r\nTAG2 OK\r\n")
            ->expect("TAG3 NOOP\r\n")
            ->reply("TAG3 OK\r\n");
        $imap  = self::imap($server);
        $first = null;

        foreach ($imap->idle(600) as $response) {
            $first = $response;

            break;
        }

        static::assertSame(
            [['3', 'EXISTS'], true, true],
            [$first, $imap->noop(), $server->isScriptComplete()],
        );
    }

    #[Test]
    public function endsIdleBeforeTheNextCommandWhenTheCallerKeepsTheGenerator(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n* 3 EXISTS\r\n")
            ->expect("DONE\r\n")
            ->reply("TAG2 OK\r\n")
            ->expect("TAG3 NOOP\r\n")
            ->reply("TAG3 OK\r\n");
        $imap      = self::imap($server);
        $responses = $imap->idle(600);
        $first     = $responses->current();

        $noop = $imap->noop();
        $responses->next();

        static::assertSame(
            [['3', 'EXISTS'], true, false, true],
            [$first, $noop, $responses->valid(), $server->isScriptComplete()],
        );
    }

    #[Test]
    public function endsIdleWhenTheCallerStopsListeningAfterDone(): void
    {
        $clock  = new SettableClock();
        $server = self::server()
            ->reply("+ idling\r\n* 2 EXISTS\r\n")
            ->expect("DONE\r\n")
            ->reply("* 3 EXISTS\r\n* 4 EXISTS\r\nTAG2 OK\r\n")
            ->expect("TAG3 NOOP\r\n")
            ->reply("TAG3 OK\r\n");
        $imap      = self::imap($server);
        $responses = $imap->idle(600, $clock);

        foreach ($responses as $response) {
            if (['3', 'EXISTS'] === $response) {
                break;
            }

            $clock->advance(600);
        }

        unset($responses);

        static::assertSame([true, true], [$imap->noop(), $server->isScriptComplete()]);
    }

    /**
     * @mago-expect lint:loop-does-not-iterate Leaving the loop at once is the behaviour under test.
     */
    #[Test]
    public function endsIdleWhenTheCallerStopsListeningBeforeTheContinuation(): void
    {
        $server = self::server()
            ->reply("* 3 EXISTS\r\n+ idling\r\n")
            ->expect("DONE\r\n")
            ->reply("TAG2 OK\r\n")
            ->expect("TAG3 NOOP\r\n")
            ->reply("TAG3 OK\r\n");
        $imap  = self::imap($server);
        $first = null;

        foreach ($imap->idle(600) as $response) {
            $first = $response;

            break;
        }

        static::assertSame(
            [['3', 'EXISTS'], true, true],
            [$first, $imap->noop(), $server->isScriptComplete()],
        );
    }

    /**
     * @mago-expect lint:loop-does-not-iterate Leaving the loop at once is the behaviour under test.
     */
    #[Test]
    public function boundsTheReplyReadWhenIdleIsEndedEarly(): void
    {
        $line   = '* OK ' . str_repeat('x', times: 595) . "\r\n";
        $server = self::server()
            ->reply("+ idling\r\n{$line}")
            ->expect("DONE\r\n")
            ->reply("{$line}TAG2 OK\r\n")
            ->expect("TAG3 NOOP\r\n")
            ->reply("TAG3 OK\r\n");
        $imap = self::imap($server);
        $imap->setResponseLimits(new ResponseLimits(
            maxLineLength: 1024,
            maxResponseSize: 1024,
        ));

        $first = null;
        foreach ($imap->idle(600) as $response) {
            $first = $response;

            break;
        }

        static::assertSame([['OK', str_repeat('x', times: 595)], true], [$first, $imap->noop()]);
    }

    #[Test]
    #[DataProvider('refusalProvider')]
    public function failsWhenTheServerRefusesIdle(string $reply, string $message): void
    {
        $server = self::server()->reply($reply);
        $imap   = self::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        iterator_to_array($imap->idle(600));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'with a reason'              => ["TAG2 BAD No mailbox selected\r\n", 'No mailbox selected'],
            'without one'                => ["TAG2 NO\r\n", 'The server refused IDLE'],
            'after an untagged response' => ["* 3 EXISTS\r\nTAG2 NO Not now\r\n", 'Not now'],
        ];
    }

    #[Test]
    public function sendsNothingMoreAfterARefusal(): void
    {
        $server = self::server()
            ->reply("TAG2 BAD No mailbox selected\r\n")
            ->expect("TAG3 NOOP\r\n")
            ->reply("TAG3 OK\r\n");
        $imap    = self::imap($server);
        $refusal = null;
        try {
            iterator_to_array($imap->idle(600));
        } catch (RuntimeException $e) {
            $refusal = $e->getMessage();
        }

        static::assertSame(
            ['No mailbox selected', true, true],
            [$refusal, $imap->noop(), $server->isScriptComplete()],
        );
    }

    #[Test]
    #[DataProvider('endProvider')]
    public function failsWhenIdleEndsWithAnError(InMemoryConnection $server, string $message): void
    {
        $imap = self::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        iterator_to_array($imap->idle(600));
    }

    /**
     * @return array<string, array{InMemoryConnection, string}>
     */
    public static function endProvider(): array
    {
        return [
            'ended by the server'          => [
                self::server()->reply("+ idling\r\nTAG2 NO Mailbox deleted\r\n"),
                'Mailbox deleted',
            ],
            'ended by the server, no text' => [
                self::server()->reply("+ idling\r\nTAG2 NO\r\n"),
                'The server ended IDLE with an error',
            ],
            'refused after DONE'           => [
                self::server()->reply("+ idling\r\n")->stall()->expect("DONE\r\n")->reply("TAG2 BAD Too late\r\n"),
                'Too late',
            ],
        ];
    }

    #[Test]
    public function stopsWhenTheServerEndsIdle(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n* 3 EXISTS\r\nTAG2 OK Timeout\r\n")
            ->expect("TAG3 NOOP\r\n")
            ->reply("TAG3 OK\r\n");
        $imap = self::imap($server);

        static::assertSame(
            [[['3', 'EXISTS']], true, true],
            [iterator_to_array($imap->idle(600)), $imap->noop(), $server->isScriptComplete()],
        );
    }

    #[Test]
    #[DataProvider('byeProvider')]
    public function failsAndClosesWhenTheServerSaysBye(string $reply, string $message): void
    {
        $server = self::server()->reply($reply);
        $imap   = self::imap($server);
        $caught = null;
        try {
            iterator_to_array($imap->idle(600));
        } catch (RuntimeException $e) {
            $caught = $e->getMessage();
        }

        static::assertSame(
            [$message, false, true],
            [$caught, $server->isConnected(), $server->isScriptComplete()],
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function byeProvider(): array
    {
        return [
            'with a reason'           => [
                "+ idling\r\n* BYE Shutting down\r\n",
                'The server closed the connection: Shutting down',
            ],
            'without one'             => ["+ idling\r\n* bye\r\n", 'The server closed the connection'],
            'before the continuation' => ["* BYE Going away\r\n", 'The server closed the connection: Going away'],
        ];
    }
}
