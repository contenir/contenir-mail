<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\TestAsset;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\ConnectionInterface;
use Contenir\Mail\Protocol\StreamConnection;
use Override;
use SensitiveParameter;

use function rtrim;

/**
 * A real connection that keeps a transcript, so a test can see how a
 * conversation with a server ended.
 */
final class RecordingConnection implements ConnectionInterface
{
    /** @var list<string> Each line sent, prefixed "C: ", and received, prefixed "S: " */
    private array $transcript = [];

    public function __construct(
        private readonly ConnectionInterface $connection = new StreamConnection(),
    ) {}

    /**
     * @return list<string>
     */
    public function transcript(): array
    {
        return $this->transcript;
    }

    #[Override]
    public function open(ConnectionConfig $config, int $port): void
    {
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
        $this->transcript[] = 'C: ' . rtrim($data, characters: "\r\n");
        $this->connection->write($data);
    }

    #[Override]
    public function readLine(int $maxLength): string
    {
        $line               = $this->connection->readLine($maxLength);
        $this->transcript[] = 'S: ' . rtrim($line, characters: "\r\n");

        return $line;
    }

    #[Override]
    public function waitForData(int $seconds): bool
    {
        return $this->connection->waitForData($seconds);
    }

    #[Override]
    public function read(int $length): string
    {
        return $this->connection->read($length);
    }

    #[Override]
    public function enableTls(): void
    {
        $this->connection->enableTls();
    }

    #[Override]
    public function setTimeout(int $seconds): void
    {
        $this->connection->setTimeout($seconds);
    }

    #[Override]
    public function close(): void
    {
        $this->connection->close();
    }
}
