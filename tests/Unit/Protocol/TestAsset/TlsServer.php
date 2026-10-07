<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\TestAsset;

use RuntimeException;
use Symfony\Component\Process\Process;

use function str_ends_with;
use function trim;

use const PHP_BINARY;

/**
 * Runs tls-server.php in a child process: a TLS server on the loopback
 * interface with a self-signed certificate, which no default trust store
 * accepts.
 */
final class TlsServer
{
    private function __construct(
        private readonly Process $process,
        public readonly int $port,
    ) {}

    /**
     * @param 'implicit'|'starttls' $mode
     */
    public static function start(string $mode): self
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/tls-server.php', $mode]);
        $process->start();

        $port = '';
        $process->waitUntil(static function (string $type, string $output) use (&$port): bool {
            $port .= $output;

            return str_ends_with($port, "\n");
        });

        if ('' === trim($port)) {
            throw new RuntimeException("The TLS server did not start: {$process->getErrorOutput()}");
        }

        return new self($process, (int) trim($port));
    }

    public function stop(): void
    {
        $this->process->stop(1);
    }
}
