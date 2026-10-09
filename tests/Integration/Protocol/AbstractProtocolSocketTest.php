<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Protocol;

use Contenir\Mail\Protocol\AbstractProtocol;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\StreamConnection;
use Contenir\Mail\Tests\TestAsset\Protocol\ExposedProtocol;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function sprintf;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;

/**
 * Opens real connections on the loopback interface.
 */
#[CoversClass(AbstractProtocol::class)]
#[Group('integration')]
final class AbstractProtocolSocketTest extends TestCase
{
    #[Test]
    public function opensAStreamConnectionByDefault(): void
    {
        $server   = stream_socket_server('tcp://127.0.0.1:0');
        $name     = (string) stream_socket_get_name($server, remote: false);
        $protocol = new ExposedProtocol('127.0.0.1');
        $protocol->open(
            new ConnectionConfig('127.0.0.1', security: Security::None, timeout: 1),
            (int) substr($name, (int) strrpos($name, needle: ':') + 1),
        );
        fclose($server);

        static::assertInstanceOf(StreamConnection::class, $protocol->currentConnection());
    }

    #[Test]
    public function reportsWhyAConnectionCannotBeOpened(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name   = (string) stream_socket_get_name($server, remote: false);
        fclose($server);
        $port = (int) substr($name, (int) strrpos($name, needle: ':') + 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Cannot connect to 127.0.0.1:%d', $port));

        (new ExposedProtocol('127.0.0.1'))->open(
            new ConnectionConfig('127.0.0.1', security: Security::None, timeout: 1),
            $port,
        );
    }
}
