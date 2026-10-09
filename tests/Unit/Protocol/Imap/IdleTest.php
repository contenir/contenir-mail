<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
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
 * IDLE (RFC 2177): untagged responses are yielded while the client listens,
 * and DONE always ends the command (contenir/contenir-mail#52).
 */
#[CoversClass(Imap::class)]
#[Group('unit')]
final class IdleTest extends TestCase
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
    public function yieldsTheUntaggedResponsesSentWhileIdling(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n* 3 EXISTS\r\n* 1 FETCH (FLAGS (\\Seen))\r\n")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("TAG2 OK IDLE terminated\r\n");

        static::assertSame(
            [[['3', 'EXISTS'], ['1', 'FETCH', ['FLAGS', ['\Seen']]]], true],
            [iterator_to_array(self::imap($server)->idle(600)), $server->isScriptComplete()],
        );
    }

    #[Test]
    public function waitsForWhatIsLeftOfTheTimeout(): void
    {
        $clock  = new SettableClock();
        $server = self::server()
            ->reply("+ idling\r\n")
            ->reply("* 3 EXISTS\r\n")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("TAG2 OK\r\n");
        $imap      = self::imap($server);
        $responses = [];

        foreach ($imap->idle(600, $clock) as $response) {
            $responses[] = $response;
            $clock->advance(200);
        }

        static::assertSame([[['3', 'EXISTS']], [600, 400]], [$responses, $server->waits()]);
    }

    #[Test]
    public function sendsDoneOnceTheTimeoutHasPassed(): void
    {
        $clock  = new SettableClock();
        $server = self::server()
            ->reply("+ idling\r\n* 3 EXISTS\r\n")
            ->expect("DONE\r\n")
            ->reply("* 4 EXISTS\r\nTAG2 OK\r\n");
        $imap      = self::imap($server);
        $responses = [];

        foreach ($imap->idle(600, $clock) as $response) {
            $responses[] = $response;
            $clock->advance(600);
        }

        static::assertSame([[['3', 'EXISTS'], ['4', 'EXISTS']], true], [$responses, $server->isScriptComplete()]);
    }

    #[Test]
    public function waitsOnceASecondIsLeft(): void
    {
        $clock  = new SettableClock();
        $server = self::server()
            ->reply("+ idling\r\n* 3 EXISTS\r\n")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("TAG2 OK\r\n");
        $imap      = self::imap($server);
        $responses = [];

        foreach ($imap->idle(600, $clock) as $response) {
            $responses[] = $response;
            $clock->advance(599);
        }

        static::assertSame([[['3', 'EXISTS']], [600, 1]], [$responses, $server->waits()]);
    }

    #[Test]
    public function sendsNothingUntilTheFirstIteration(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 IDLE\r\nTAG1 OK\r\n");

        self::imap($server)->idle();

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function skipsLinesThatAreNeitherUntaggedNorTheReply(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n+ still here\r\n* 3 EXISTS\r\n")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("+ ok\r\nTAG2 OK\r\n");

        static::assertSame([['3', 'EXISTS']], iterator_to_array(self::imap($server)->idle(600)));
    }

    #[Test]
    public function readsALiteralInAnUntaggedResponse(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n* 1 FETCH (FLAGS ({4}\r\n\$Odd))\r\n")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("TAG2 OK\r\n");

        static::assertSame(
            [['1', 'FETCH', ['FLAGS', ['$Odd']]]],
            iterator_to_array(self::imap($server)->idle(600)),
        );
    }

    #[Test]
    public function refusesAResponseOverTheLimitWhileIdling(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n* 1 FETCH (X {1001}\r\n" . str_repeat('x', times: 1001) . ")\r\n")
            ->expect("DONE\r\n")
            ->reply("TAG2 OK\r\n");
        $imap = self::imap($server);
        $imap->setResponseLimits(new ResponseLimits(
            maxLineLength: 1024,
            maxResponseSize: 1024,
        ));
        $error = null;
        try {
            iterator_to_array($imap->idle(600));
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        static::assertSame(
            ["The server's response exceeds the limit of 1024 bytes", true],
            [$error, $server->isScriptComplete()],
        );
    }

    #[Test]
    public function boundsEachResponseRatherThanTheWholeIdle(): void
    {
        $line   = '* OK ' . str_repeat('x', times: 595) . "\r\n";
        $server = self::server()
            ->reply("+ idling\r\n{$line}{$line}")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("{$line}TAG2 OK\r\n");
        $imap = self::imap($server);
        $imap->setResponseLimits(new ResponseLimits(
            maxLineLength: 1024,
            maxResponseSize: 1024,
        ));

        static::assertCount(3, iterator_to_array($imap->idle(600)));
    }

    #[Test]
    public function yieldsTheUntaggedResponsesSentBeforeTheContinuation(): void
    {
        $server = self::server()
            ->reply("* 2 EXPUNGE\r\n* 9 EXISTS\r\n+ idling\r\n* 10 EXISTS\r\n")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("TAG2 OK\r\n");

        static::assertSame(
            [['2', 'EXPUNGE'], ['9', 'EXISTS'], ['10', 'EXISTS']],
            iterator_to_array(self::imap($server)->idle(600), preserve_keys: false),
        );
    }

    #[Test]
    public function needsTheIdleCapability(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1\r\nTAG1 OK\r\n");
        $imap = self::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server does not offer IDLE');

        $imap->idle();
    }

    #[Test]
    public function idlesWithoutAskingOnceImap4Rev2IsEnabled(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev2\r\nTAG1 OK\r\n")
            ->expect("TAG2 LOGIN \"jo\" \"secret\"\r\n")
            ->reply("TAG2 OK\r\n")
            ->expect("TAG3 ENABLE IMAP4rev2\r\n")
            ->reply("* ENABLED IMAP4rev2\r\nTAG3 OK\r\n")
            ->expect("TAG4 IDLE\r\n")
            ->reply("+ idling\r\n")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("TAG4 OK\r\n");
        $imap = self::imap($server);
        $imap->login('jo', 'secret');

        static::assertSame([[], true], [iterator_to_array($imap->idle(600)), $server->isScriptComplete()]);
    }

    #[Test]
    #[DataProvider('timeoutProvider')]
    public function refusesATimeoutUnderASecond(int $timeout): void
    {
        $imap = self::imap(ScriptedServer::imapGreeting());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The IDLE timeout must be at least one second');

        $imap->idle($timeout);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function timeoutProvider(): array
    {
        return [
            'zero'     => [0],
            'negative' => [-1],
        ];
    }

    #[Test]
    public function idlesForASecond(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("TAG2 OK\r\n");

        iterator_to_array(self::imap($server)->idle(1, new SettableClock()));

        static::assertSame([1], $server->waits());
    }

    #[Test]
    public function idlesForTwentyNineMinutesByDefault(): void
    {
        $server = self::server()
            ->reply("+ idling\r\n")
            ->stall()
            ->expect("DONE\r\n")
            ->reply("TAG2 OK\r\n");

        iterator_to_array(self::imap($server)->idle(clock: new SettableClock()));

        static::assertSame([1740], $server->waits());
    }
}
