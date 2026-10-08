<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Pop3;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Protocol\ResponseLimits;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function md5;
use function preg_quote;
use function str_repeat;

#[CoversClass(Pop3::class)]
#[Group('unit')]
final class CommandTest extends TestCase
{
    /**
     * A client whose first command must be $request, answered with $response.
     */
    private static function pop3(string $request, string $response): Pop3
    {
        return ScriptedServer::pop3(ScriptedServer::pop3Greeting()->expect($request)->reply($response)->hangUp());
    }

    #[Test]
    public function readsCapabilities(): void
    {
        $pop3 = self::pop3("CAPA\r\n", "+OK list follows\r\nTOP\r\nUIDL\r\n.\r\n");

        static::assertSame(["TOP\r", "UIDL\r", ''], $pop3->capa());
    }

    #[Test]
    public function logsInWithUserAndPass(): void
    {
        $server = ScriptedServer::pop3Greeting()
            ->expect("USER user\r\n")
            ->reply("+OK\r\n")
            ->expect("PASS secret\r\n")
            ->reply("+OK\r\n")
            ->hangUp();
        ScriptedServer::pop3($server)->login('user', 'secret');

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function logsInWithApopWhenTheServerOffersIt(): void
    {
        $timestamp = '<1896.697170952@dbc.mtview.ca.us>';
        $server    = ScriptedServer::pop3Greeting($timestamp)
            ->expect(self::apop($timestamp))
            ->reply("+OK\r\n")
            ->hangUp();
        ScriptedServer::pop3($server)->login('user', 'secret');

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function fallsBackToUserAndPassWhenApopIsRefused(): void
    {
        $timestamp = '<1@host>';
        $server    = ScriptedServer::pop3Greeting($timestamp)
            ->expect(self::apop($timestamp))
            ->reply("-ERR no\r\n")
            ->expect("USER user\r\n")
            ->reply("+OK\r\n")
            ->expect("PASS secret\r\n")
            ->reply("+OK\r\n")
            ->hangUp();
        ScriptedServer::pop3($server)->login('user', 'secret');

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function skipsApopWhenAsked(): void
    {
        $server = ScriptedServer::pop3Greeting('<1@host>')
            ->expect("USER user\r\n")
            ->reply("+OK\r\n")
            ->expect("PASS secret\r\n")
            ->reply("+OK\r\n")
            ->hangUp();
        ScriptedServer::pop3($server)->login('user', 'secret', false);

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function failsWhenThePasswordIsRefused(): void
    {
        $pop3 = ScriptedServer::pop3(
            ScriptedServer::pop3Greeting()
                ->expect("USER user\r\n")
                ->reply("+OK\r\n")
                ->expect("PASS wrong\r\n")
                ->reply("-ERR invalid\r\n")
                ->hangUp(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('last request failed');

        $pop3->login('user', 'wrong');
    }

    /**
     * The reason the server gives is part of the message (laminas/laminas-mail#187).
     */
    #[Test]
    #[DataProvider('refusalProvider')]
    public function reportsTheReasonTheServerGives(string $reply, string $expected): void
    {
        $pop3 = self::pop3("NOOP\r\n", $reply);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($expected, delimiter: '/') . '$/D');

        $pop3->request('NOOP');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'reason'             => [
                "-ERR [IN-USE] mailbox locked\r\n",
                'last request failed: [IN-USE] mailbox locked',
            ],
            'no reason'          => ["-ERR\r\n", 'last request failed'],
            'control characters' => ["-ERR a\x1B[31m\x00b\r\n", 'last request failed: a [31m b'],
        ];
    }

    #[Test]
    public function readsTheMailboxStatus(): void
    {
        $pop3 = self::pop3("STAT\r\n", "+OK 2 320\r\n");

        $pop3->status($messages, $octets);

        static::assertSame([2, 320], [$messages, $octets]);
    }

    #[Test]
    public function readsAMalformedStatusAsEmpty(): void
    {
        $pop3 = self::pop3("STAT\r\n", "+OK\r\n");

        $pop3->status($messages, $octets);

        static::assertSame([0, 0], [$messages, $octets]);
    }

    #[Test]
    public function listsTheSizeOfOneMessage(): void
    {
        $pop3 = self::pop3("LIST 2\r\n", "+OK 2 200\r\n");

        static::assertSame(200, $pop3->getList(2));
    }

    #[Test]
    public function readsAMalformedSizeAsZero(): void
    {
        $pop3 = self::pop3("LIST 2\r\n", "+OK\r\n");

        static::assertSame(0, $pop3->getList(2));
    }

    #[Test]
    public function listsTheSizesOfAllMessages(): void
    {
        $pop3 = self::pop3("LIST\r\n", "+OK 2 messages\r\n1 120\r\n\r\n 2 200\r\n3\r\n.\r\n");

        static::assertSame([1 => 120, 2 => 200, 3 => 0], $pop3->getList());
    }

    #[Test]
    public function readsTheUniqueIdOfOneMessage(): void
    {
        $pop3 = self::pop3("UIDL 2\r\n", "+OK 2 QhdPYR:00WBw1Ph7x7\r\n");

        static::assertSame('QhdPYR:00WBw1Ph7x7', $pop3->uniqueid(2));
    }

    #[Test]
    public function readsAMalformedUniqueIdAsEmpty(): void
    {
        $pop3 = self::pop3("UIDL 2\r\n", "+OK\r\n");

        static::assertSame('', $pop3->uniqueid(2));
    }

    #[Test]
    public function readsTheUniqueIdsOfAllMessages(): void
    {
        $pop3 = self::pop3("UIDL\r\n", "+OK\r\n1 whqtswO00WBw418f9t5JxYwZ\r\n\r\n2 a b\r\n3\r\n.\r\n");

        static::assertSame([1 => 'whqtswO00WBw418f9t5JxYwZ', 2 => 'a b', 3 => ''], $pop3->uniqueid());
    }

    #[Test]
    public function retrievesAMessageAndRemovesDotStuffing(): void
    {
        $pop3 = self::pop3("RETR 1\r\n", "+OK 30 octets\r\nSubject: x\r\n\r\n..hidden\r\n.\r\n");

        static::assertSame("Subject: x\r\n\r\n.hidden\r\n", $pop3->retrieve(1));
    }

    #[Test]
    public function readsTheHeadersWithTop(): void
    {
        $pop3 = self::pop3("TOP 1 3\r\n", "+OK\r\nSubject: x\r\n.\r\n");

        static::assertSame("Subject: x\r\n", $pop3->top(1, 3));
    }

    #[Test]
    public function readsNoBodyLinesForANegativeCount(): void
    {
        $pop3 = self::pop3("TOP 1 0\r\n", "+OK\r\nSubject: x\r\n.\r\n");

        static::assertSame("Subject: x\r\n", $pop3->top(1, -4));
    }

    #[Test]
    public function recordsThatTopIsSupported(): void
    {
        $pop3 = self::pop3("TOP 1 0\r\n", "+OK\r\n.\r\n");
        $pop3->top(1);

        static::assertTrue($pop3->hasTop);
    }

    #[Test]
    public function fallsBackToRetrieveWhenTopFails(): void
    {
        $server = ScriptedServer::pop3Greeting()
            ->expect("TOP 1 0\r\n")
            ->reply("-ERR unknown\r\n")
            ->expect("RETR 1\r\n")
            ->reply("+OK\r\nwhole\r\n.\r\n")
            ->hangUp();

        static::assertSame("whole\r\n", ScriptedServer::pop3($server)->top(1, 0, true));
    }

    #[Test]
    public function recordsThatTopIsNotSupported(): void
    {
        $server = ScriptedServer::pop3Greeting()
            ->expect("TOP 1 0\r\n")
            ->reply("-ERR unknown\r\n")
            ->expect("RETR 1\r\n")
            ->reply("+OK\r\nwhole\r\n.\r\n")
            ->hangUp();
        $pop3 = ScriptedServer::pop3($server);
        $pop3->top(1, 0, true);

        static::assertFalse($pop3->hasTop);
    }

    #[Test]
    public function rethrowsTheTopFailureWithoutAFallback(): void
    {
        $pop3 = self::pop3("TOP 1 0\r\n", "-ERR unknown\r\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('last request failed');

        $pop3->top(1);
    }

    #[Test]
    public function retrievesDirectlyOnceTopIsKnownToBeUnsupported(): void
    {
        $pop3         = self::pop3("RETR 2\r\n", "+OK\r\nwhole\r\n.\r\n");
        $pop3->hasTop = false;

        static::assertSame("whole\r\n", $pop3->top(2, 0, true));
    }

    #[Test]
    public function failsOnceTopIsKnownToBeUnsupportedWithoutAFallback(): void
    {
        $pop3         = ScriptedServer::pop3(ScriptedServer::pop3Greeting()->hangUp());
        $pop3->hasTop = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('top not supported and no fallback wanted');

        $pop3->top(1);
    }

    #[DataProvider('simpleCommandProvider')]
    #[Test]
    public function sendsSimpleCommands(string $method, array $arguments, string $request): void
    {
        $server = ScriptedServer::pop3Greeting()->expect($request)->reply("+OK\r\n")->hangUp();
        ScriptedServer::pop3($server)->{$method}(...$arguments);

        static::assertTrue($server->isScriptComplete());
    }

    /**
     * @return array<string, array{string, list<int>, string}>
     */
    public static function simpleCommandProvider(): array
    {
        return [
            'noop'     => ['noop', [], "NOOP\r\n"],
            'delete'   => ['delete', [3], "DELE 3\r\n"],
            'undelete' => ['undelete', [], "RSET\r\n"],
        ];
    }

    #[Test]
    public function failsWhenTheServerAnswersAnError(): void
    {
        $pop3 = self::pop3("DELE 9\r\n", "-ERR no such message\r\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('last request failed');

        $pop3->delete(9);
    }

    #[Test]
    public function returnsTheResponseMessage(): void
    {
        $pop3 = self::pop3("NOOP\r\n", "+OK  fine thanks\r\n");

        static::assertSame(' fine thanks', $pop3->request('NOOP'));
    }

    #[Test]
    public function readsAStatusWithoutAMessage(): void
    {
        $pop3 = self::pop3("NOOP\r\n", "+OK\r\n");

        static::assertSame('', $pop3->request('NOOP'));
    }

    #[DataProvider('invalidMessageNumberProvider')]
    #[Test]
    public function refusesMessageNumbersBelowOne(string $method, int $msgno): void
    {
        $pop3 = ScriptedServer::pop3(ScriptedServer::pop3Greeting()->hangUp());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Message numbers start at 1');

        $pop3->{$method}($msgno);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function invalidMessageNumberProvider(): array
    {
        return [
            'list zero'         => ['getList', 0],
            'uidl negative'     => ['uniqueid', -1],
            'top zero'          => ['top', 0],
            'retrieve negative' => ['retrieve', -3],
            'delete zero'       => ['delete', 0],
        ];
    }

    #[Test]
    public function refusesAMultiLineResponseOverTheResponseLimit(): void
    {
        $pop3 = self::pop3(
            "RETR 1\r\n",
            "+OK\r\n" . str_repeat(str_repeat('a', times: 1000) . "\r\n", times: 5) . ".\r\n",
        );
        $pop3->setResponseLimits(new ResponseLimits(1024, 4096));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The server's response exceeds the limit of 4096 bytes");

        $pop3->retrieve(1);
    }

    #[Test]
    public function acceptsAMultiLineResponseOfExactlyTheResponseLimit(): void
    {
        $message = str_repeat(str_repeat('a', times: 1022) . "\r\n", times: 4);
        $pop3    = self::pop3("RETR 1\r\n", "+OK\r\n{$message}.\r\n");
        $pop3->setResponseLimits(new ResponseLimits(1024, 4096));

        static::assertSame($message, $pop3->retrieve(1));
    }

    #[Test]
    public function refusesALineOverTheLineLimit(): void
    {
        $pop3 = self::pop3("RETR 1\r\n", "+OK\r\n" . str_repeat('a', times: 2000) . "\r\n.\r\n");
        $pop3->setResponseLimits(new ResponseLimits(1024, 4096));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server sent a line longer than 1024 bytes');

        $pop3->retrieve(1);
    }

    #[Test]
    public function acceptsALineOfExactlyTheLineLimit(): void
    {
        $line = str_repeat('a', times: 1022) . "\r\n";
        $pop3 = self::pop3("RETR 1\r\n", "+OK\r\n{$line}.\r\n");
        $pop3->setResponseLimits(new ResponseLimits(1024, 4096));

        static::assertSame($line, $pop3->retrieve(1));
    }

    #[Test]
    public function failsWhenTheConnectionClosesBeforeTheEndOfAMultiLineResponse(): void
    {
        $pop3 = self::pop3("RETR 1\r\n", "+OK\r\npartial\r\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the connection is closed');

        $pop3->retrieve(1);
    }

    #[Test]
    public function failsWhenTheServerStopsAnswering(): void
    {
        $pop3 = ScriptedServer::pop3(ScriptedServer::pop3Greeting()->expect("NOOP\r\n")->stall()->hangUp());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('timed out');

        $pop3->noop();
    }

    private static function apop(string $timestamp): string
    {
        $digest = md5("{$timestamp}secret");

        return "APOP user {$digest}\r\n";
    }

    #[Test]
    public function readsOneBodyLineWithTop(): void
    {
        $pop3 = self::pop3("TOP 1 1\r\n", "+OK\r\nSubject: x\r\n\r\nbody\r\n.\r\n");

        static::assertSame("Subject: x\r\n\r\nbody\r\n", $pop3->top(1, 1));
    }

    #[Test]
    public function sendsARequestAndReadsItsResponseSeparately(): void
    {
        $pop3 = self::pop3("NOOP\r\n", "+OK fine\r\n");
        $pop3->sendRequest('NOOP');

        static::assertSame('fine', $pop3->readResponse());
    }
}
