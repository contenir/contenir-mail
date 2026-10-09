<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use Override;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

use function array_map;
use function explode;
use function implode;
use function rtrim;
use function str_replace;

/**
 * Logs what passes over a connection to a PSR-3 logger, at debug level, with credentials
 * redacted: "C: " before what the client sends, "S: " before what the server sends.
 *
 * ```php
 * new ConnectionConfig(host: 'imap.example.com', logger: $logger);
 * new Imap(connection: new LoggingConnection(new StreamConnection(), $logger));
 * ```
 *
 * Passwords, access tokens, APOP digests and SASL responses are sent with writeSecret(),
 * and logged as "[redacted]", or as the command they start with its arguments redacted,
 * such as "TAG1 LOGIN [redacted]". Lines sent with write() that start LOGIN, AUTHENTICATE,
 * AUTH, USER, PASS or APOP are redacted the same way. Everything else is logged as it
 * is, message contents included, so treat the log as you would the mailbox.
 *
 * Needs psr/log, which this package only suggests.
 *
 * @api
 *
 * @mago-expect lint:too-many-methods A decorator has each of the connection's methods.
 */
final readonly class LoggingConnection implements RedactingConnectionInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private LoggerInterface $logger,
    ) {}

    /**
     * The connection, logging to $logger when one is given; a LoggingConnection is not wrapped again.
     *
     * @internal The protocols decorate their connection with ConnectionConfig::$logger when they connect.
     */
    public static function decorate(ConnectionInterface $connection, ?LoggerInterface $logger): ConnectionInterface
    {
        if (null === $logger || $connection instanceof self) {
            return $connection;
        }

        return new self($connection, $logger);
    }

    #[Override]
    public function open(ConnectionConfig $config, int $port): void
    {
        $this->logger->debug("Connecting to {$config->host}:{$port}");
        $this->connection->open($config, $port);
    }

    #[Override]
    public function isConnected(): bool
    {
        return $this->connection->isConnected();
    }

    #[Override]
    public function write(#[SensitiveParameter] string $data): void
    {
        $this->logger->debug('C: ' . implode("\n", array_map(Redaction::redact(...), self::lines($data))));
        $this->connection->write($data);
    }

    /**
     * Only the command that the bytes start with is logged, its arguments redacted; any other
     * bytes are logged as "[redacted]".
     */
    #[Override]
    public function writeSecret(#[SensitiveParameter] string $data): void
    {
        $line     = self::lines($data)[0];
        $redacted = Redaction::redact($line);
        $this->logger->debug('C: ' . ($redacted === $line ? AbstractProtocol::REDACTED : $redacted));
        Redaction::writeSecret($this->connection, $data);
    }

    #[Override]
    public function readLine(int $maxLength): string
    {
        $line = $this->connection->readLine($maxLength);
        $this->logger->debug('S: ' . rtrim($line, characters: "\r\n"));

        return $line;
    }

    #[Override]
    public function waitUntilReadable(int $seconds): bool
    {
        return $this->connection->waitUntilReadable($seconds);
    }

    #[Override]
    public function read(int $length): string
    {
        $data = $this->connection->read($length);
        $this->logger->debug('S: ' . implode("\n", self::lines($data)));

        return $data;
    }

    #[Override]
    public function enableTls(): void
    {
        $this->connection->enableTls();
        $this->logger->debug('TLS started');
    }

    #[Override]
    public function setTimeout(int $seconds): void
    {
        $this->connection->setTimeout($seconds);
    }

    #[Override]
    public function close(): void
    {
        if ($this->connection->isConnected()) {
            $this->logger->debug('Closing the connection');
        }

        $this->connection->close();
    }

    /**
     * The lines of the bytes, without their line ends.
     *
     * @return non-empty-list<string>
     */
    private static function lines(#[SensitiveParameter] string $data): array
    {
        return explode("\n", str_replace(
            search: "\r\n",
            replace: "\n",
            subject: rtrim($data, characters: "\r\n"),
        ));
    }
}
