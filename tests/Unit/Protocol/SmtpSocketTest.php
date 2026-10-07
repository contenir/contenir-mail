<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp;
use Contenir\Mail\Tests\Unit\TestAsset\SocketSmtp;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function bin2hex;
use function error_clear_last;
use function error_get_last;
use function fclose;
use function file_put_contents;
use function fwrite;
use function is_resource;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function openssl_x509_export;
use function random_bytes;
use function str_contains;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function unlink;

use const OPENSSL_KEYTYPE_RSA;
use const PHP_BINARY;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/**
 * The socket handling: opening a connection and the STARTTLS upgrade, on local sockets only.
 */
#[CoversClass(Smtp::class)]
#[Group('unit')]
final class SmtpSocketTest extends TestCase
{
    /** @var list<resource> */
    private array $sockets = [];

    private ?Process $server = null;

    private ?string $pem = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        if (null !== $this->pem) {
            unlink($this->pem);
        }

        foreach ($this->sockets as $socket) {
            if (! is_resource($socket)) {
                continue;
            }

            fclose($socket);
        }
    }

    #[Test]
    public function connectsToServer(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        static::assertIsResource($server);
        $this->sockets[] = $server;
        $address         = (string) stream_socket_get_name($server, remote: false);
        $port            = (int) substr($address, strrpos($address, needle: ':') + 1);

        $smtp = new class(new ConnectionConfig('127.0.0.1', $port, Security::None, timeout: 1)) extends Smtp {
            public ?string $transport = null;

            #[Override]
            protected function openSocket(string $transport): void
            {
                parent::openSocket($transport);
                $this->transport = $transport;
            }
        };
        $smtp->connect();

        static::assertSame('tcp', $smtp->transport);
    }

    #[Test]
    public function closesSocketOnDisconnect(): void
    {
        [$client] = $this->pair();
        $smtp = new SocketSmtp('mail.example.com');
        $smtp->useSocket($client);

        $smtp->disconnect();

        static::assertFalse(is_resource($client));
    }

    /**
     * STARTTLS on a socket with default settings verifies the server certificate, so a
     * self-signed certificate is refused.
     */
    #[Test]
    public function verifiesServerCertificateByDefault(): void
    {
        $smtp = new SocketSmtp('mail.example.com');
        $smtp->useSocket($this->tlsServerConnection([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Unable to start TLS: .*certificate verify failed/s');

        $smtp->upgradeToTls();
    }

    #[Test]
    public function upgradesToTlsTwelveOrLater(): void
    {
        $client = $this->tlsServerConnection(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $smtp   = new SocketSmtp('mail.example.com');
        $smtp->useSocket($client);

        $smtp->upgradeToTls();

        static::assertContains(stream_get_meta_data($client)['crypto']['protocol'] ?? null, ['TLSv1.2', 'TLSv1.3']);
    }

    #[Test]
    public function leavesNoPhpErrorBehindAfterFailedHandshake(): void
    {
        [$client, $server] = $this->pair();
        fclose($server);
        $smtp = new SocketSmtp('mail.example.com');
        $smtp->useSocket($client);
        error_clear_last();

        try {
            $smtp->upgradeToTls();
        } catch (RuntimeException) {
            static::assertNull(error_get_last());
            return;
        }

        static::fail('TLS started on a closed socket');
    }

    #[Test]
    public function reportsHandshakeThatFailsWithoutWarning(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        static::assertIsResource($server);
        $this->sockets[] = $server;
        $client          = stream_socket_client('tcp://' . stream_socket_get_name($server, remote: false));
        static::assertIsResource($client);
        $this->sockets[] = $client;
        $accepted        = stream_socket_accept($server);
        static::assertIsResource($accepted);
        fclose($accepted);
        $smtp = new SocketSmtp('mail.example.com');
        $smtp->useSocket($client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to start TLS: ');

        $smtp->upgradeToTls();
    }

    #[Test]
    public function reportsFailedConnection(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        static::assertIsResource($server);
        $address = (string) stream_socket_get_name($server, remote: false);
        fclose($server);

        $smtp = new Smtp(
            new ConnectionConfig(
                '127.0.0.1',
                (int) substr($address, strrpos($address, needle: ':') + 1),
                Security::None,
                timeout: 1,
            ),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot connect to 127.0.0.1:');

        $smtp->connect();
    }

    #[Test]
    public function refusesTlsWithoutConnection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No connection has been established to mail.example.com');

        (new SocketSmtp('mail.example.com'))->upgradeToTls();
    }

    /**
     * STARTTLS response injection (CVE-2011-0411 class): text sent after the 220 arrives
     * before encryption, so it must not be read as if it came over TLS.
     */
    #[Test]
    public function refusesTlsWhenServerSentDataAfterAgreeing(): void
    {
        [$client, $server] = $this->pair();
        fwrite($server, data: "220 2.0.0 Ready to start TLS\r\n250 injected reply\r\n");
        $smtp = new SocketSmtp('mail.example.com');
        $smtp->useSocket($client);
        $smtp->readReply(220);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server sent data after agreeing to STARTTLS; not starting TLS');

        $smtp->upgradeToTls();
    }

    #[Test]
    public function reportsFailedTlsHandshake(): void
    {
        [$client, $server] = $this->pair();
        fclose($server);
        $smtp = new SocketSmtp('mail.example.com');
        $smtp->useSocket($client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Unable to start TLS: \S/');

        $smtp->upgradeToTls();
    }

    /**
     * A plain connection to a local TLS server that waits for the client to start TLS.
     *
     * @param array<string, array<string, bool>> $contextOptions
     * @return resource
     */
    private function tlsServerConnection(array $contextOptions)
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        static::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'mail.example.com'], $key);
        static::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, ca_certificate: null, private_key: $key, days: 1);
        static::assertNotFalse($cert);
        openssl_x509_export($cert, $certificate);
        openssl_pkey_export($key, $privateKey);
        $this->pem = sys_get_temp_dir() . '/contenir_smtp_tls_' . bin2hex(random_bytes(6)) . '.pem';
        file_put_contents($this->pem, $certificate . $privateKey);

        $this->server = new Process([PHP_BINARY, __DIR__ . '/../TestAsset/tls-server.php', $this->pem]);
        $this->server->start();
        $this->server->waitUntil(static fn(string $type, string $output): bool => str_contains($output, "\n"));
        $port = (int) $this->server->getOutput();

        $context = stream_context_create($contextOptions);
        $client  = stream_socket_client("tcp://127.0.0.1:{$port}", timeout: 5, context: $context);
        static::assertIsResource($client);
        $this->sockets[] = $client;

        return $client;
    }

    /**
     * @return array{resource, resource}
     */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        static::assertIsArray($pair);
        [$client, $server] = $pair;
        $this->sockets[] = $client;
        $this->sockets[] = $server;

        return [$client, $server];
    }
}
