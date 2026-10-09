<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset\Protocol;

use RuntimeException;
use Symfony\Component\Process\Process;

use function explode;
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
        /** The server's certificate, which a client may trust as its certificate authority */
        public readonly string $certificate,
    ) {}

    /**
     * @param 'implicit'|'starttls'|'smtp' $mode
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

        [$number, $certificate] = explode(' ', trim($port), limit: 2) + [1 => ''];

        return new self($process, (int) $number, $certificate);
    }

    public function stop(): void
    {
        $this->process->stop(1);
    }
}
