<?php

/**
 * @see       https://github.com/laminas/laminas-mail for the canonical source repository
 */

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\AbstractProtocol;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\ProtocolTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function str_contains;

use const PHP_BINARY;

/**
 * @group      Contenir_Mail
 * @covers Contenir\Mail\Protocol\AbstractProtocol<extended>
 */
final class AbstractProtocolTest extends TestCase
{
    private Process $process;

    protected function setUp(): void
    {
        $this->process = new Process([
            PHP_BINARY,
            '-S',
            '127.0.0.1:8080',
            '-t',
            __DIR__ . '/HttpStatusService',
        ]);
        $this->process->start();
        $this->process->waitUntil(static fn(string $type, string $output): bool => str_contains($output, 'started'));
    }

    protected function tearDown(): void
    {
        $this->process->stop();
    }

    public function testExceptionShouldBeRaisedWhenConnectionHasTimedOut(): void
    {
        $protocol = new class ('127.0.0.1', 8080) extends AbstractProtocol {
            use ProtocolTrait;

            public function connect(): void
            {
                $this->_disconnect();
                $this->socket = $this->setupSocket('tcp', $this->host, $this->port, 2);
            }

            public function send(string $path, ?int $readTimeout): string
            {
                $this->_send('GET ' . $path . ' HTTP/1.1');
                $this->_send('Host: ' . $this->host);
                $this->_send('');

                return $this->_receive($readTimeout);
            }
        };

        $protocol->connect();
        self::assertSame('HTTP/1.1 200 OK' . AbstractProtocol::EOL, $protocol->send('/', null));

        $protocol->connect();
        $this->expectExceptionObject(new RuntimeException('127.0.0.1 has timed out'));
        $protocol->send('/?sleep=3', 1);
    }
}
