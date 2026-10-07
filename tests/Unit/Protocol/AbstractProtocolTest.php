<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\AbstractProtocol;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\StreamConnection;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ExposedProtocol;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fgets;
use function is_resource;
use function serialize;
use function sprintf;
use function str_repeat;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use function unserialize;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

#[CoversClass(AbstractProtocol::class)]
#[Group('unit')]
final class AbstractProtocolTest extends TestCase
{
    private static function protocol(InMemoryConnection $server): ExposedProtocol
    {
        $server->open(new ConnectionConfig(
            host: 'smtp.example.com',
            security: Security::None,
        ), 25);

        return new ExposedProtocol('smtp.example.com', 25, $server);
    }

    #[Test]
    public function refusesAnInvalidHost(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match the expected structure for a DNS hostname');

        new ExposedProtocol('bad host name!');
    }

    #[Test]
    public function sendsTheRequestWithALineEnd(): void
    {
        $server = (new InMemoryConnection())->expect("NOOP\r\n");
        self::protocol($server)->send('NOOP');

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function returnsTheNumberOfBytesSent(): void
    {
        static::assertSame(6, self::protocol((new InMemoryConnection())->expect("NOOP\r\n"))->send('NOOP'));
    }

    #[Test]
    public function logsRequestsAndResponses(): void
    {
        $protocol = self::protocol(
            (new InMemoryConnection())->expect("NOOP\r\n")
                ->reply("250 OK\r\n"),
        );
        $protocol->send('NOOP');
        $protocol->receive();

        static::assertSame("NOOP\r\n250 OK\r\n", $protocol->getLog());
    }

    #[Test]
    public function remembersTheLastRequest(): void
    {
        $protocol = self::protocol((new InMemoryConnection())->expect("NOOP\r\n"));
        $protocol->send('NOOP');

        static::assertSame('NOOP', $protocol->getRequest());
    }

    #[DataProvider('credentialProvider')]
    #[Test]
    public function keepsCredentialsOutOfTheLog(string $request, string $logged): void
    {
        $protocol = self::protocol((new InMemoryConnection())->expect("{$request}\r\n"));
        $protocol->send($request);

        static::assertSame("{$logged}\r\n", $protocol->getLog());
    }

    #[DataProvider('credentialProvider')]
    #[Test]
    public function keepsCredentialsOutOfTheLastRequest(string $request, string $logged): void
    {
        $protocol = self::protocol((new InMemoryConnection())->expect("{$request}\r\n"));
        $protocol->send($request);

        static::assertSame($logged, $protocol->getRequest());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function credentialProvider(): array
    {
        return [
            'IMAP LOGIN'                  => ['A1 LOGIN user "secret"', 'A1 LOGIN [redacted]'],
            'IMAP AUTHENTICATE response'  => [
                'A1 AUTHENTICATE PLAIN AHVzZXIAc2VjcmV0',
                'A1 AUTHENTICATE PLAIN [redacted]',
            ],
            'SMTP AUTH initial response'  => ['AUTH PLAIN AHVzZXIAc2VjcmV0', 'AUTH PLAIN [redacted]'],
            'SMTP AUTH lower case'        => ['auth plain AHVzZXIAc2VjcmV0', 'auth plain [redacted]'],
            'POP3 USER'                   => ['USER alice', 'USER [redacted]'],
            'POP3 PASS'                   => ['PASS secret', 'PASS [redacted]'],
            'POP3 PASS with spaces'       => ['PASS  my secret', 'PASS [redacted]'],
            'POP3 APOP'                   => ['APOP alice c4c9334bac560ecc979e58001b3e22fb', 'APOP [redacted]'],
            'AUTH mechanism only'         => ['AUTH LOGIN', 'AUTH LOGIN'],
            'AUTH mechanism and spaces'   => ['AUTH LOGIN ', 'AUTH LOGIN [redacted]'],
            'PASS without an argument'    => ['PASS', 'PASS'],
            'command without credentials' => ['MAIL FROM:<pass@example.com>', 'MAIL FROM:<pass@example.com>'],
            'word starting like PASS'     => ['PASSWORD x', 'PASSWORD x'],
            'CRLF in a credential'        => ["PASS a\r\nb", 'PASS [redacted]'],
            'AUTH LOGIN initial response' => ['AUTH LOGIN dXNlcg==', 'AUTH LOGIN [redacted]'],
            'AUTH with only a space'      => ['AUTH ', 'AUTH [redacted]'],
            'EHLO naming a user'          => ['EHLO user', 'EHLO user'],
            'VRFY of a user'              => ['VRFY user alice', 'VRFY user [redacted]'],
        ];
    }

    #[Test]
    public function logsASensitiveRequestAsItsRedactedForm(): void
    {
        $protocol = self::protocol((new InMemoryConnection())->expect("dXNlcgBzZWNyZXQ=\r\n"));
        $protocol->sendSecret('dXNlcgBzZWNyZXQ=');

        static::assertSame("[redacted]\r\n", $protocol->getLog());
    }

    #[Test]
    public function logsASensitiveRequestAsItIsTold(): void
    {
        $protocol = self::protocol((new InMemoryConnection())->expect("dXNlcgBzZWNyZXQ=\r\n"));
        $protocol->sendSecret('dXNlcgBzZWNyZXQ=', '[password]');

        static::assertSame('[password]', $protocol->getRequest());
    }

    #[Test]
    public function sendsASensitiveRequestUnchanged(): void
    {
        $server = (new InMemoryConnection())->expect("dXNlcgBzZWNyZXQ=\r\n");
        self::protocol($server)->sendSecret('dXNlcgBzZWNyZXQ=');

        static::assertSame("dXNlcgBzZWNyZXQ=\r\n", $server->written());
    }

    #[Test]
    public function returnsTheBytesSentForASensitiveRequest(): void
    {
        static::assertSame(4, self::protocol((new InMemoryConnection())->expect("ab\r\n"))->sendSecret('ab'));
    }

    #[Test]
    public function refusesToSendWithoutAConnection(): void
    {
        $protocol = new ExposedProtocol('smtp.example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No connection has been established to smtp.example.com');

        $protocol->send('NOOP');
    }

    #[Test]
    public function refusesToSendOnAClosedConnection(): void
    {
        $protocol = new ExposedProtocol('smtp.example.com', 25, new InMemoryConnection());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No connection has been established to smtp.example.com');

        $protocol->send('NOOP');
    }

    #[Test]
    public function failsWhenTheRequestCannotBeSent(): void
    {
        $protocol = self::protocol((new InMemoryConnection())->hangUp());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not send request to smtp.example.com');

        $protocol->send('NOOP');
    }

    #[Test]
    public function receivesALine(): void
    {
        static::assertSame("250 OK\r\n", self::protocol((new InMemoryConnection())->reply("250 OK\r\n"))->receive());
    }

    #[Test]
    public function receivesALongLineInPiecesOf1023Bytes(): void
    {
        $line = str_repeat('a', times: 2000) . "\r\n";

        static::assertSame(1023, strlen(self::protocol((new InMemoryConnection())->reply($line))->receive()));
    }

    #[Test]
    public function setsThePerCommandTimeout(): void
    {
        $server = (new InMemoryConnection())->reply("250 OK\r\n");
        self::protocol($server)->receive(300);

        static::assertSame(300, $server->timeout());
    }

    #[Test]
    public function keepsTheTimeoutWhenNoneIsGiven(): void
    {
        $server = (new InMemoryConnection())->reply("250 OK\r\n");
        self::protocol($server)->receive();

        static::assertNull($server->timeout());
    }

    #[Test]
    public function reportsATimeout(): void
    {
        $protocol = self::protocol((new InMemoryConnection())->stall());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('smtp.example.com has timed out');

        $protocol->receive(1);
    }

    #[Test]
    public function reportsAClosedConnection(): void
    {
        $protocol = self::protocol((new InMemoryConnection())->hangUp());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not read from smtp.example.com');

        $protocol->receive();
    }

    #[Test]
    public function refusesToReceiveWithoutAConnection(): void
    {
        $protocol = new ExposedProtocol('smtp.example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No connection has been established to smtp.example.com');

        $protocol->receive();
    }

    #[Test]
    public function returnsTheMessageOfAnExpectedResponse(): void
    {
        static::assertSame(
            "ready\r\n",
            self::protocol((new InMemoryConnection())->reply("220 ready\r\n"))->expect(220),
        );
    }

    #[Test]
    public function readsAMultiLineResponse(): void
    {
        $protocol = self::protocol(
            (new InMemoryConnection())->reply("250-smtp.example.com\r\n250-PIPELINING\r\n250 STARTTLS\r\n"),
        );

        static::assertSame("STARTTLS\r\n", $protocol->expect('250'));
    }

    #[Test]
    public function keepsTheLinesOfTheLastResponse(): void
    {
        $protocol = self::protocol((new InMemoryConnection())->reply("250-one\r\n250 two\r\n"));
        $protocol->expect(250);

        static::assertSame(["250-one\r\n", "250 two\r\n"], $protocol->getResponse());
    }

    #[Test]
    public function acceptsAnyOfSeveralCodes(): void
    {
        static::assertSame(
            "ok\r\n",
            self::protocol((new InMemoryConnection())->reply("251 ok\r\n"))->expect([250, 251]),
        );
    }

    #[Test]
    public function throwsTheServerMessageForAnUnexpectedCode(): void
    {
        $protocol = self::protocol((new InMemoryConnection())->reply("550 5.1.1 no such user\r\n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("5.1.1 no such user\r\n");
        $this->expectExceptionCode(550);

        $protocol->expect(250);
    }

    #[Test]
    public function joinsTheLinesOfAnUnexpectedMultiLineResponse(): void
    {
        $protocol = self::protocol((new InMemoryConnection())->reply("554-first\r\n554 second\r\n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("first\r\n second\r\n");

        $protocol->expect(250);
    }

    #[Test]
    public function failsWhenOnlyALaterLineHasAnUnexpectedCode(): void
    {
        $protocol = self::protocol((new InMemoryConnection())->reply("250-first\r\n554 second\r\n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("second\r\n");

        $protocol->expect(250);
    }

    #[Test]
    public function treatsAnUnexpectedCodeWithoutAMessageAsAFailure(): void
    {
        $protocol = self::protocol((new InMemoryConnection())->reply("550\r\n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('');
        $this->expectExceptionCode(550);

        $protocol->expect(250);
    }

    #[Test]
    public function treatsALineWithoutASeparatorAsAFailure(): void
    {
        $protocol = self::protocol(
            (new InMemoryConnection())->reply('garbage')
                ->hangUp(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('');
        $this->expectExceptionCode(0);

        $protocol->expect(250);
    }

    #[Test]
    public function keepsTheMostRecentLogEntries(): void
    {
        $protocol = new ExposedProtocol();
        $protocol->setMaximumLog(2);
        $protocol->addLog('a');
        $protocol->addLog('b');
        $protocol->addLog('c');

        static::assertSame('bc', $protocol->getLog());
    }

    #[Test]
    public function keepsEveryLogEntryWithANegativeMaximum(): void
    {
        $protocol = new ExposedProtocol();
        $protocol->setMaximumLog(-1);
        for ($i = 0; $i < 70; ++$i) {
            $protocol->addLog('x');
        }

        static::assertSame(70, strlen($protocol->getLog()));
    }

    #[Test]
    public function keeps64LogEntriesByDefault(): void
    {
        static::assertSame(64, (new ExposedProtocol())->getMaximumLog());
    }

    #[Test]
    public function readsTheMaximumLogAsAnInteger(): void
    {
        $protocol = new ExposedProtocol();
        $protocol->setMaximumLog('5');

        static::assertSame(5, $protocol->getMaximumLog());
    }

    #[Test]
    public function resetsTheLog(): void
    {
        $protocol = new ExposedProtocol();
        $protocol->addLog('a');
        $protocol->resetLog();

        static::assertSame('', $protocol->getLog());
    }

    #[Test]
    public function talksOverASocketASubclassOpened(): void
    {
        [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $protocol = new ExposedProtocol('smtp.example.com');
        $protocol->useSocket($client);
        $protocol->send('NOOP');

        static::assertSame("NOOP\r\n", fgets($peer));
    }

    #[Test]
    public function followsTheSocketWhenASubclassReplacesIt(): void
    {
        [$first, $firstPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $protocol = new ExposedProtocol('smtp.example.com');
        $protocol->useSocket($first);
        $protocol->send('NOOP');
        $protocol->useSocket($client);
        $protocol->send('RSET');

        static::assertSame(["NOOP\r\n", "RSET\r\n"], [fgets($firstPeer), fgets($peer)]);
    }

    #[Test]
    public function wrapsTheSameSocketOnlyOnce(): void
    {
        [$client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $protocol = new ExposedProtocol('smtp.example.com');
        $protocol->useSocket($client);

        static::assertSame($protocol->currentConnection(), $protocol->currentConnection());
    }

    #[Test]
    public function disconnectClosesTheSocket(): void
    {
        [$client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $protocol = new ExposedProtocol('smtp.example.com');
        $protocol->useSocket($client);
        $protocol->disconnect();

        static::assertFalse(is_resource($client));
    }

    #[Test]
    public function disconnectClosesTheConnection(): void
    {
        $server = new InMemoryConnection();
        self::protocol($server)->disconnect();

        static::assertFalse($server->isConnected());
    }

    #[Test]
    public function disconnectsWhenDestroyed(): void
    {
        $server   = new InMemoryConnection();
        $protocol = self::protocol($server);

        unset($protocol);

        static::assertFalse($server->isConnected());
    }

    #[Test]
    public function opensASocketToARemote(): void
    {
        $server   = stream_socket_server('tcp://127.0.0.1:0');
        $name     = (string) stream_socket_get_name($server, remote: false);
        $protocol = new ExposedProtocol('127.0.0.1');
        $protocol->connectTo("tcp://{$name}");
        fclose($server);

        static::assertInstanceOf(StreamConnection::class, $protocol->currentConnection());
    }

    #[Test]
    public function reportsThatTheSocketIsOpen(): void
    {
        $server   = stream_socket_server('tcp://127.0.0.1:0');
        $name     = (string) stream_socket_get_name($server, remote: false);
        $protocol = new ExposedProtocol('127.0.0.1');
        $result   = $protocol->connectTo("tcp://{$name}");
        fclose($server);

        static::assertTrue($result);
    }

    #[Test]
    public function reportsWhyASocketCannotBeOpened(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name   = (string) stream_socket_get_name($server, remote: false);
        fclose($server);
        $port = substr($name, (int) strrpos($name, needle: ':') + 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'Could not open socket: stream_socket_client(): Unable to connect to tcp://127.0.0.1:%s',
            $port,
        ));

        (new ExposedProtocol('127.0.0.1'))->connectTo("tcp://{$name}");
    }

    #[Test]
    public function cannotBeSerialized(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(ExposedProtocol::class . ' cannot be serialized');

        serialize(new ExposedProtocol());
    }

    #[Test]
    public function refusesToUnserializeSoACraftedPayloadNeverReachesTheDestructor(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(ExposedProtocol::class . ' cannot be unserialized');

        unserialize(sprintf('O:%d:"%s":0:{}', strlen(ExposedProtocol::class), ExposedProtocol::class));
    }

    #[Test]
    public function keepsOnlyTheLastLogEntryWithAMaximumOfZero(): void
    {
        $protocol = new ExposedProtocol();
        $protocol->setMaximumLog(0);
        $protocol->addLog('a');
        $protocol->addLog('b');

        static::assertSame('b', $protocol->getLog());
    }
}
