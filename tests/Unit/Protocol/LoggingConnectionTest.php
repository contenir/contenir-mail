<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\LoggingConnection;
use Contenir\Mail\Protocol\Redaction;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\TestAsset\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

use function array_column;
use function array_unique;

/**
 * Logging what passes over a connection, with credentials redacted.
 */
#[CoversClass(LoggingConnection::class)]
#[CoversClass(Redaction::class)]
#[Group('unit')]
final class LoggingConnectionTest extends TestCase
{
    private static function config(): ConnectionConfig
    {
        return new ConnectionConfig('mail.example.com', security: Security::None);
    }

    /**
     * @return array{LoggingConnection, InMemoryConnection, RecordingLogger}
     */
    private static function open(InMemoryConnection $server): array
    {
        $logger     = new RecordingLogger();
        $connection = new LoggingConnection($server, $logger);
        $connection->open(self::config(), 143);

        return [$connection, $server, $logger];
    }

    #[Test]
    public function logsWhatIsSentAndReceivedAtDebugLevel(): void
    {
        [$connection, $server, $logger] = self::open(
            (new InMemoryConnection())->reply("* OK ready\r\n")
                ->expect("TAG1 NOOP\r\n")
                ->reply("TAG1 OK done\r\n")
                ->hangUp(),
        );

        $connection->readLine(1024);
        $connection->write("TAG1 NOOP\r\n");
        $connection->readLine(1024);
        $connection->close();

        static::assertSame(
            [
                [
                    'Connecting to mail.example.com:143',
                    'S: * OK ready',
                    'C: TAG1 NOOP',
                    'S: TAG1 OK done',
                    'Closing the connection',
                ],
                [LogLevel::DEBUG],
                false,
            ],
            [$logger->messages(), array_unique(array_column($logger->records(), 'level')), $server->isConnected()],
        );
    }

    #[Test]
    public function logsALiteralAsItIsRead(): void
    {
        [$connection, , $logger] = self::open((new InMemoryConnection())->reply("Subject: hi\r\n\r\nbody"));

        $read = $connection->read(19);

        static::assertSame(["Subject: hi\r\n\r\nbody", "S: Subject: hi\n\nbody"], [$read, $logger->messages()[1]]);
    }

    #[Test]
    public function logsTheStartOfTls(): void
    {
        [$connection, $server, $logger] = self::open((new InMemoryConnection())->startTls());

        $connection->enableTls();

        static::assertSame([true, 'TLS started'], [$server->isTlsEnabled(), $logger->messages()[1]]);
    }

    #[Test]
    public function logsNothingWhenClosingAClosedConnection(): void
    {
        $logger     = new RecordingLogger();
        $connection = new LoggingConnection(new InMemoryConnection(), $logger);

        $connection->close();

        static::assertSame([], $logger->messages());
    }

    #[Test]
    public function passesWaitsAndTimeoutsOn(): void
    {
        [$connection, $server] = self::open(
            (new InMemoryConnection())->stall()
                ->reply("+OK\r\n"),
        );

        $connection->setTimeout(7);

        static::assertSame(
            [false, true, true, 7],
            [
                $connection->waitUntilReadable(5),
                $connection->waitUntilReadable(5),
                $connection->isConnected(),
                $server->timeout(),
            ],
        );
    }

    /**
     * Credentials written without being marked secret are still redacted, line by line.
     */
    #[Test]
    #[DataProvider('credentialLineProvider')]
    public function redactsTheArgumentsOfCredentialCommands(string $data, string $logged): void
    {
        [$connection, , $logger] = self::open((new InMemoryConnection())->expect($data));

        $connection->write($data);

        static::assertSame("C: {$logged}", $logger->messages()[1]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function credentialLineProvider(): array
    {
        return [
            'IMAP LOGIN'        => ["TAG1 LOGIN \"jo\" \"hunter2\"\r\n", 'TAG1 LOGIN [redacted]'],
            'POP3 PASS'         => ["PASS hunter2\r\n", 'PASS [redacted]'],
            'SMTP AUTH PLAIN'   => ["AUTH PLAIN AGpvAGh1bnRlcjI=\r\n", 'AUTH PLAIN [redacted]'],
            'a command without' => ["AUTH LOGIN\r\n", 'AUTH LOGIN'],
            'several lines'     => [
                "MAIL FROM:<jo@example.com>\r\nPASS hunter2\r\nRCPT TO:<al@example.com>\r\n",
                "MAIL FROM:<jo@example.com>\nPASS [redacted]\nRCPT TO:<al@example.com>",
            ],
            'a bare line feed'  => ["NOOP\nPASS hunter2\n", "NOOP\nPASS [redacted]"],
        ];
    }

    #[Test]
    #[DataProvider('secretProvider')]
    public function logsSecretsOnlyAsRedacted(string $data, string $logged): void
    {
        [$connection, $server, $logger] = self::open((new InMemoryConnection())->expect($data));

        $connection->writeSecret($data);

        static::assertSame([$data, "C: {$logged}"], [$server->written(), $logger->messages()[1]]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function secretProvider(): array
    {
        return [
            'a SASL response'            => ["dXNlcj1qbwFhdXRoPUJlYXJlciB4AQE=\r\n", '[redacted]'],
            'an empty response'          => ["\r\n", '[redacted]'],
            'a command with credentials' => [
                "TAG2 AUTHENTICATE XOAUTH2 dXNlcj1qbw==\r\n",
                'TAG2 AUTHENTICATE XOAUTH2 [redacted]',
            ],
            'a literal'                  => ['hunter2', '[redacted]'],
            'only the first line'        => ["TAG1 LOGIN {7}\r\nhunter2\r\n", 'TAG1 LOGIN [redacted]'],
            'a line that is no command'  => ["LOGINhunter2\r\n", '[redacted]'],
        ];
    }

    /**
     * A logging connection around another passes secrets on as secrets, so neither logs them.
     */
    #[Test]
    public function passesSecretsOnToAConnectionThatRecordsThem(): void
    {
        $inner      = new RecordingLogger();
        $outer      = new RecordingLogger();
        $server     = (new InMemoryConnection())->expect("PASS hunter2\r\n");
        $connection = new LoggingConnection(new LoggingConnection($server, $inner), $outer);
        $connection->open(self::config(), 110);

        $connection->writeSecret("PASS hunter2\r\n");

        static::assertSame(
            ['C: PASS [redacted]', 'C: PASS [redacted]', "PASS hunter2\r\n"],
            [$inner->messages()[1], $outer->messages()[1], $server->written()],
        );
    }

    #[Test]
    public function decoratesAConnectionWithALogger(): void
    {
        $server = new InMemoryConnection();
        $logger = new RecordingLogger();

        $connection = LoggingConnection::decorate($server, $logger);
        $connection->open(self::config(), 143);

        static::assertSame(
            [LoggingConnection::class, ['Connecting to mail.example.com:143']],
            [$connection::class, $logger->messages()],
        );
    }

    #[Test]
    public function leavesAConnectionAsItIsWithoutALogger(): void
    {
        $server = new InMemoryConnection();

        static::assertSame($server, LoggingConnection::decorate($server, null));
    }

    #[Test]
    public function doesNotDecorateALoggingConnectionAgain(): void
    {
        $connection = new LoggingConnection(new InMemoryConnection(), new RecordingLogger());

        static::assertSame($connection, LoggingConnection::decorate($connection, new RecordingLogger()));
    }
}
