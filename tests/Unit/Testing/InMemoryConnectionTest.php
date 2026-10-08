<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Testing;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Exception\TimeoutException;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Testing\InMemoryConnection;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryConnection::class)]
#[Group('unit')]
final class InMemoryConnectionTest extends TestCase
{
    private static function opened(InMemoryConnection $connection): InMemoryConnection
    {
        $connection->open(new ConnectionConfig(
            host: 'mail.example.com',
            security: Security::None,
        ), 143);

        return $connection;
    }

    #[Test]
    public function recordsTheConfigurationItWasOpenedWith(): void
    {
        $config = new ConnectionConfig(
            host: 'mail.example.com',
            security: Security::StartTls,
        );
        $connection = new InMemoryConnection();

        $connection->open($config, 587);

        static::assertSame([$config, 587], [$connection->openedWith(), $connection->openedPort()]);
    }

    #[Test]
    public function hasNotBeenOpenedAtFirst(): void
    {
        $connection = new InMemoryConnection();

        static::assertSame([null, null, false], [
            $connection->openedWith(),
            $connection->openedPort(),
            $connection->isConnected(),
        ]);
    }

    #[Test]
    public function isConnectedOnceOpened(): void
    {
        static::assertTrue(self::opened(new InMemoryConnection())->isConnected());
    }

    #[Test]
    public function isNotConnectedOnceClosed(): void
    {
        $connection = self::opened(new InMemoryConnection());

        $connection->close();

        static::assertFalse($connection->isConnected());
    }

    #[Test]
    public function startsWithTlsForImplicitTls(): void
    {
        $connection = new InMemoryConnection();

        $connection->open(new ConnectionConfig(
            host: 'mail.example.com',
            security: Security::Tls,
        ), 993);

        static::assertTrue($connection->isTlsEnabled());
    }

    #[Test]
    public function startsWithoutTlsForStartTls(): void
    {
        $connection = new InMemoryConnection();

        $connection->open(new ConnectionConfig(
            host: 'mail.example.com',
            security: Security::StartTls,
        ), 143);

        static::assertFalse($connection->isTlsEnabled());
    }

    #[Test]
    public function refusesTheConnectionWhenScripted(): void
    {
        $connection = (new InMemoryConnection())->refuse('no route to host');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot connect to mail.example.com:143: no route to host');

        self::opened($connection);
    }

    #[Test]
    public function connectsOnASecondAttemptAfterARefusal(): void
    {
        $connection = (new InMemoryConnection())->refuse();
        try {
            self::opened($connection);
        } catch (RuntimeException) {
            self::opened($connection);
        }

        static::assertTrue($connection->isConnected());
    }

    #[Test]
    public function returnsRepliesLineByLine(): void
    {
        $connection = self::opened((new InMemoryConnection())->reply("one\r\ntwo\r\n"));
        $connection->readLine(100);

        static::assertSame("two\r\n", $connection->readLine(100));
    }

    #[Test]
    public function joinsConsecutiveReplies(): void
    {
        $connection = self::opened(
            (new InMemoryConnection())->reply('on')
                ->reply("e\r\n"),
        );

        static::assertSame("one\r\n", $connection->readLine(100));
    }

    #[Test]
    public function skipsEmptyReplies(): void
    {
        $connection = self::opened(
            (new InMemoryConnection())->reply('')
                ->hangUp(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the connection is closed');

        $connection->readLine(100);
    }

    #[Test]
    public function returnsALongLineInPieces(): void
    {
        $connection = self::opened((new InMemoryConnection())->reply("abcdef\r\n"));
        $connection->readLine(4);

        static::assertSame("ef\r\n", $connection->readLine(4));
    }

    #[Test]
    public function returnsALineOfExactlyTheMaximumLength(): void
    {
        $connection = self::opened((new InMemoryConnection())->reply("abcd\nef\n"));

        static::assertSame("abcd\n", $connection->readLine(5));
    }

    #[Test]
    public function returnsAFinalLineWithoutALineFeed(): void
    {
        $connection = self::opened(
            (new InMemoryConnection())->reply('end')
                ->hangUp(),
        );

        static::assertSame('end', $connection->readLine(100));
    }

    #[Test]
    public function readsExactlyTheBytesAsked(): void
    {
        $connection = self::opened(
            (new InMemoryConnection())->reply('ab')
                ->reply("c\r\nd"),
        );

        static::assertSame("abc\r\n", $connection->read(5));
    }

    #[Test]
    public function readsAcrossRepliesSeparatedByAnExpectation(): void
    {
        $connection = self::opened(
            (new InMemoryConnection())->reply('ab')
                ->expect('x')
                ->reply('cd'),
        );
        $connection->read(2);
        $connection->write('x');

        static::assertSame('cd', $connection->read(2));
    }

    #[Test]
    public function readsNothingForAZeroLength(): void
    {
        static::assertSame('', self::opened(new InMemoryConnection())->read(0));
    }

    #[Test]
    public function failsToReadWhenTheServerHangsUp(): void
    {
        $connection = self::opened(
            (new InMemoryConnection())->reply('ab')
                ->hangUp(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read from the server: the connection is closed');

        $connection->read(3);
    }

    #[Test]
    public function failsToReadWhenTheScriptIsOver(): void
    {
        $connection = self::opened(new InMemoryConnection());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read from the server: the connection is closed');

        $connection->readLine(10);
    }

    #[Test]
    public function timesOutWhereTheServerStalls(): void
    {
        $connection = self::opened((new InMemoryConnection())->stall());

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('The server has timed out');

        $connection->readLine(10);
    }

    #[Test]
    public function continuesAfterAStall(): void
    {
        $connection = self::opened(
            (new InMemoryConnection())->stall()
                ->reply("late\r\n"),
        );
        $timeout = '';
        try {
            $connection->readLine(10);
        } catch (TimeoutException $e) {
            $timeout = $e->getMessage();
        }

        static::assertSame(['The server has timed out', "late\r\n"], [$timeout, $connection->readLine(10)]);
    }

    #[Test]
    #[DataProvider('waitProvider')]
    public function reportsWhetherTheServerHasSentSomething(InMemoryConnection $script, bool $expected): void
    {
        static::assertSame($expected, self::opened($script)->waitForData(5));
    }

    /**
     * @return array<string, array{InMemoryConnection, bool}>
     */
    public static function waitProvider(): array
    {
        return [
            'a reply'               => [(new InMemoryConnection())->reply("* 3 EXISTS\r\n"), true],
            'a hang-up'             => [(new InMemoryConnection())->hangUp(), true],
            'the end of the script' => [new InMemoryConnection(), true],
            'a stall'               => [
                (new InMemoryConnection())->stall()
                    ->reply("late\r\n"),
                false,
            ],
        ];
    }

    #[Test]
    public function reportsBytesLeftFromAReply(): void
    {
        $connection = self::opened(
            (new InMemoryConnection())->reply("one\r\ntwo\r\n")
                ->stall(),
        );
        $connection->readLine(10);

        static::assertTrue($connection->waitForData(5));
    }

    #[Test]
    public function readsWhatComesAfterTheStallItWaitedThrough(): void
    {
        $connection = self::opened(
            (new InMemoryConnection())->stall()
                ->reply("late\r\n"),
        );

        static::assertSame([false, true, "late\r\n"], [
            $connection->waitForData(5),
            $connection->waitForData(5),
            $connection->readLine(10),
        ]);
    }

    #[Test]
    public function recordsHowLongTheClientWaited(): void
    {
        $connection = self::opened((new InMemoryConnection())->stall()->stall());
        $connection->waitForData(30);
        $connection->waitForData(12);

        static::assertSame([30, 12], $connection->waits());
    }

    #[Test]
    public function refusesToWaitWhileTheScriptWaitsForTheClient(): void
    {
        $connection = self::opened((new InMemoryConnection())->expect("DONE\r\n"));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            "The client waits for the server, but the script expects the client to send 'DONE",
        );

        $connection->waitForData(1);
    }

    #[Test]
    public function refusesToWaitWhileTheScriptWaitsForTls(): void
    {
        $connection = self::opened((new InMemoryConnection())->startTls());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The client waits for the server, but the script expects the client to enable TLS',
        );

        $connection->waitForData(1);
    }

    #[Test]
    public function refusesToReadWhileTheScriptWaitsForTheClient(): void
    {
        $connection = self::opened((new InMemoryConnection())->expect("NOOP\r\n"));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("The client reads, but the script expects the client to send 'NOOP");

        $connection->readLine(10);
    }

    #[Test]
    public function refusesToReadWhileTheScriptWaitsForTls(): void
    {
        $connection = self::opened((new InMemoryConnection())->startTls());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The client reads, but the script expects the client to enable TLS');

        $connection->readLine(10);
    }

    #[Test]
    public function recordsWhatTheClientWrites(): void
    {
        $connection = self::opened(
            (new InMemoryConnection())->expect("A\r\n")
                ->expect("B\r\n"),
        );
        $connection->write("A\r\n");
        $connection->write("B\r\n");

        static::assertSame("A\r\nB\r\n", $connection->written());
    }

    #[Test]
    public function acceptsAnExpectationWrittenInPieces(): void
    {
        $connection = self::opened((new InMemoryConnection())->expect("LOGIN x\r\n"));
        $connection->write('LOG');
        $connection->write("IN x\r\n");

        static::assertTrue($connection->isScriptComplete());
    }

    #[Test]
    public function acceptsTwoExpectationsWrittenAtOnce(): void
    {
        $connection = self::opened(
            (new InMemoryConnection())->expect("A\r\n")
                ->expect("B\r\n"),
        );
        $connection->write("A\r\nB\r\n");

        static::assertTrue($connection->isScriptComplete());
    }

    #[Test]
    public function refusesBytesTheScriptDoesNotExpect(): void
    {
        $connection = self::opened((new InMemoryConnection())->expect("NOOP\r\n"));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("The client sent 'NOOX', but the script expects the client to send 'NOOP");

        $connection->write('NOOX');
    }

    #[Test]
    public function refusesBytesThatContinueAnExpectationWrongly(): void
    {
        $connection = self::opened((new InMemoryConnection())->expect("NOOP\r\n"));
        $connection->write('NO');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("The client sent 'NOX'");

        $connection->write('X');
    }

    #[DataProvider('unexpectedWriteProvider')]
    #[Test]
    public function refusesWritesWhenTheScriptExpectsSomethingElse(
        InMemoryConnection $connection,
        string $expected,
    ): void {
        self::opened($connection);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("The client sent 'x', but the script expects {$expected}");

        $connection->write('x');
    }

    /**
     * @return array<string, array{InMemoryConnection, string}>
     */
    public static function unexpectedWriteProvider(): array
    {
        return [
            'nothing more' => [new InMemoryConnection(), 'nothing more'],
            'a reply'      => [(new InMemoryConnection())->reply('y'), 'the server to reply'],
            'a stall'      => [(new InMemoryConnection())->stall(), 'the server to stall'],
            'TLS'          => [(new InMemoryConnection())->startTls(), 'the client to enable TLS'],
            'TLS to fail'  => [(new InMemoryConnection())->failTls(), 'the client to enable TLS'],
            'a refusal'    => [
                (new InMemoryConnection())->expect('')
                    ->refuse(),
                'the server to refuse',
            ],
        ];
    }

    #[Test]
    public function failsToWriteAfterTheServerHangsUp(): void
    {
        $connection = self::opened((new InMemoryConnection())->hangUp());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot write to the server: the connection is closed');

        $connection->write("QUIT\r\n");
    }

    #[DataProvider('closedOperationProvider')]
    #[Test]
    public function refusesToWorkBeforeBeingOpened(string $operation): void
    {
        $connection = new InMemoryConnection();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No connection has been established');

        match ($operation) {
            'write'    => $connection->write('x'),
            'readLine' => $connection->readLine(1),
            'read'     => $connection->read(1),
            'wait'     => $connection->waitForData(1),
            default    => $connection->enableTls(),
        };
    }

    /**
     * @return array<string, array{string}>
     */
    public static function closedOperationProvider(): array
    {
        return [
            'write'      => ['write'],
            'read line'  => ['readLine'],
            'read'       => ['read'],
            'wait'       => ['wait'],
            'enable TLS' => ['enableTls'],
        ];
    }

    #[Test]
    public function enablesTlsWhereTheScriptSays(): void
    {
        $connection = self::opened((new InMemoryConnection())->startTls());
        $connection->enableTls();

        static::assertTrue($connection->isTlsEnabled());
    }

    #[Test]
    public function failsTheTlsHandshakeWhereTheScriptSays(): void
    {
        $connection = self::opened((new InMemoryConnection())->failTls('bad certificate'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot enable TLS: bad certificate');

        $connection->enableTls();
    }

    #[Test]
    public function refusesTlsWhenServerBytesAreUnread(): void
    {
        $connection = self::opened(
            (new InMemoryConnection())->reply("OK\r\ninjected\r\n")
                ->startTls(),
        );
        $connection->readLine(100);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server sent data before TLS was negotiated; refusing to continue');

        $connection->enableTls();
    }

    #[DataProvider('unexpectedTlsProvider')]
    #[Test]
    public function refusesTlsTheScriptDoesNotExpect(InMemoryConnection $connection, string $expected): void
    {
        self::opened($connection);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("The client enabled TLS, but the script expects {$expected}");

        $connection->enableTls();
    }

    /**
     * @return array<string, array{InMemoryConnection, string}>
     */
    public static function unexpectedTlsProvider(): array
    {
        return [
            'nothing more' => [new InMemoryConnection(), 'nothing more'],
            'a write'      => [(new InMemoryConnection())->expect('A'), "the client to send 'A'"],
            'a hang-up'    => [(new InMemoryConnection())->hangUp(), 'the server to hang up'],
        ];
    }

    #[Test]
    public function recordsTheTimeout(): void
    {
        $connection = new InMemoryConnection();
        $connection->setTimeout(300);

        static::assertSame(300, $connection->timeout());
    }

    #[Test]
    public function hasNoTimeoutAtFirst(): void
    {
        static::assertNull((new InMemoryConnection())->timeout());
    }

    #[DataProvider('completeScriptProvider')]
    #[Test]
    public function reportsAScriptThatHasRun(InMemoryConnection $connection): void
    {
        static::assertTrue($connection->isScriptComplete());
    }

    /**
     * @return array<string, array{InMemoryConnection}>
     */
    public static function completeScriptProvider(): array
    {
        return [
            'empty'                => [new InMemoryConnection()],
            'only a final hang-up' => [(new InMemoryConnection())->hangUp()],
        ];
    }

    #[Test]
    public function reportsUnreadServerBytes(): void
    {
        $connection = self::opened((new InMemoryConnection())->reply("a\nb\n"));
        $connection->readLine(10);

        static::assertFalse($connection->isScriptComplete());
    }

    #[DataProvider('incompleteScriptProvider')]
    #[Test]
    public function reportsStepsThatHaveNotRun(InMemoryConnection $connection): void
    {
        static::assertFalse($connection->isScriptComplete());
    }

    /**
     * @return array<string, array{InMemoryConnection}>
     */
    public static function incompleteScriptProvider(): array
    {
        return [
            'a step'               => [(new InMemoryConnection())->expect('x')],
            'a step and a hang-up' => [(new InMemoryConnection())->expect('x')
                ->hangUp()],
            'two hang-ups'         => [(new InMemoryConnection())->hangUp()->hangUp()],
            'a hang-up and a step' => [(new InMemoryConnection())->hangUp()
                ->expect('x')],
        ];
    }

    #[Test]
    public function joinsEveryConsecutiveReply(): void
    {
        $connection = self::opened(
            (new InMemoryConnection())->reply('a')
                ->reply('b')
                ->reply('c')
                ->hangUp(),
        );

        static::assertSame('abc', $connection->readLine(100));
    }

    #[Test]
    public function readsAllTheBytesTheServerSent(): void
    {
        $connection = self::opened(
            (new InMemoryConnection())->reply('abc')
                ->hangUp(),
        );

        static::assertSame('abc', $connection->read(3));
    }

    #[Test]
    public function readsASingleByte(): void
    {
        $connection = self::opened((new InMemoryConnection())->reply('abc'));

        static::assertSame('a', $connection->read(1));
    }

    /**
     * After the client strays from the script, the connection is closed, so a later call such
     * as a destructor logging out gets an ordinary protocol error rather than a second mismatch.
     */
    #[Test]
    #[DataProvider('strayProvider')]
    public function closesOnceTheClientStraysFromTheScript(InMemoryConnection $script, string $call): void
    {
        $connection = self::opened($script);
        try {
            match ($call) {
                'write'     => $connection->write("WRONG\r\n"),
                'read'      => $connection->readLine(1024),
                'enableTls' => $connection->enableTls(),
                'wait'      => $connection->waitForData(1),
            };
        } catch (LogicException) {
            static::assertFalse($connection->isConnected());

            return;
        }

        static::fail('The script mismatch was not reported');
    }

    /**
     * @return array<string, array{InMemoryConnection, string}>
     */
    public static function strayProvider(): array
    {
        return [
            'unexpected command'     => [(new InMemoryConnection())->expect("RIGHT\r\n"), 'write'],
            'command after the end'  => [new InMemoryConnection(), 'write'],
            'read instead of write'  => [(new InMemoryConnection())->expect("RIGHT\r\n"), 'read'],
            'TLS instead of a write' => [(new InMemoryConnection())->expect("RIGHT\r\n"), 'enableTls'],
            'wait instead of write'  => [(new InMemoryConnection())->expect("RIGHT\r\n"), 'wait'],
        ];
    }

    #[Test]
    public function reportsAClosedConnectionAfterAMismatch(): void
    {
        $connection = self::opened((new InMemoryConnection())->expect("RIGHT\r\n"));
        try {
            $connection->write("WRONG\r\n");
        } catch (LogicException) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('No connection has been established');

            $connection->write("LOGOUT\r\n");
        }
    }
}
