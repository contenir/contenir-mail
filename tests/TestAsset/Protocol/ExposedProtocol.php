<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset\Protocol;

use Contenir\Mail\Protocol\AbstractProtocol;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\ConnectionInterface;
use Override;

/**
 * Exposes AbstractProtocol's protected API, which Protocol\Smtp builds on.
 */
final class ExposedProtocol extends AbstractProtocol
{
    #[Override]
    public function connect(): bool
    {
        return true;
    }

    public function open(ConnectionConfig $config, int $port): void
    {
        $this->openConnection($config, $port);
    }

    public function send(string $request): int
    {
        return $this->_send($request);
    }

    public function sendSecret(string $request, string $loggedAs = self::REDACTED): int
    {
        return $this->sendSensitive($request, $loggedAs);
    }

    public function receive(?int $timeout = null): string
    {
        return $this->_receive($timeout);
    }

    /**
     * @param string|int|array<string|int> $code
     */
    public function expect(string|int|array $code, ?int $timeout = null): string
    {
        return $this->_expect($code, $timeout);
    }

    public function disconnect(): void
    {
        $this->_disconnect();
    }

    public function addLog(string $value): void
    {
        $this->_addLog($value);
    }

    public function currentConnection(): ConnectionInterface
    {
        return $this->connection();
    }
}
