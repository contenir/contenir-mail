<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Transport;

use Contenir\Mail\Message;
use Contenir\Mail\Protocol\Exception\RuntimeException as ProtocolRuntimeException;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Transport\Smtp;
use Contenir\Mail\Transport\SmtpConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;

/**
 * Connects to a closed port on the loopback interface, so the transport
 * builds its connection from the settings and fails to open it.
 */
#[CoversClass(Smtp::class)]
#[Group('integration')]
final class SmtpConnectionTest extends TestCase
{
    private const string AUTH_VALUE = 'not-a-real-credential';

    #[Test]
    public function createsConnectionFromConfig(): void
    {
        $config = new SmtpConfig(
            host: '127.0.0.1',
            port: self::closedPort(),
            timeout: 1,
            auth: new Login('orders', self::AUTH_VALUE),
        );
        $transport = new Smtp($config);

        try {
            $transport->send(self::message());
        } catch (ProtocolRuntimeException) {
            $connection = $transport->getConnection();
            static::assertSame(
                [$config->connection, $config->auth],
                [$connection?->getConnectionConfig(), $connection?->getAuthenticator()],
            );
            return;
        }

        static::fail('A closed port accepted the connection');
    }

    #[Test]
    public function passesInsecureAuthSettingToConnection(): void
    {
        $config = new SmtpConfig(
            host: '127.0.0.1',
            port: self::closedPort(),
            security: Security::None,
            timeout: 1,
            auth: new Login('orders', self::AUTH_VALUE),
            allowInsecureAuth: true,
        );
        $transport = new Smtp($config);

        try {
            $transport->send(self::message());
        } catch (ProtocolRuntimeException) {
            static::assertTrue($transport->getConnection()?->allowsInsecureAuth());
            return;
        }

        static::fail('A closed port accepted the connection');
    }

    private static function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        static::assertIsResource($server);
        $address = (string) stream_socket_get_name($server, remote: false);
        fclose($server);

        return (int) substr($address, strrpos($address, needle: ':') + 1);
    }

    private static function message(): Message
    {
        return (new Message())->addTo('test@example.com')
            ->addFrom('ralph@example.com')
            ->setSubject('Testing Contenir\Mail\Transport\Smtp')
            ->setBody('This is only a test.');
    }
}
