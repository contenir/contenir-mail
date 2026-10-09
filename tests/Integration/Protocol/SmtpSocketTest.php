<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Protocol;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\TestAsset\Protocol\TlsServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function error_clear_last;
use function error_get_last;
use function fclose;
use function fwrite;
use function is_resource;
use function stream_get_contents;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;

/**
 * The connection handling: opening a connection and the STARTTLS upgrade, on local sockets only.
 */
#[CoversClass(Smtp::class)]
#[Group('integration')]
final class SmtpSocketTest extends TestCase
{
    /**
     * Seconds for each TLS handshake: generous, as a loaded machine can take
     * several seconds to complete one.
     */
    private const int TLS_TIMEOUT = 30;

    /** @var list<resource> */
    private array $sockets = [];

    private ?TlsServer $tlsServer = null;

    protected function tearDown(): void
    {
        $this->tlsServer?->stop();
        foreach ($this->sockets as $socket) {
            if (! is_resource($socket)) {
                continue;
            }

            fclose($socket);
        }
    }

    #[Test]
    public function talksToServerOverTheNetwork(): void
    {
        [$smtp] = $this->plainSession("220 ready\r\n250 mail.example.com\r\n221 bye\r\n");
        $smtp->helo('localhost');

        static::assertTrue($smtp->hasSession());
    }

    #[Test]
    public function closesConnectionOnDisconnect(): void
    {
        [$smtp, $accepted] = $this->plainSession("220 ready\r\n250 mail.example.com\r\n221 bye\r\n");
        $smtp->helo('localhost');
        $smtp->disconnect();

        static::assertSame("EHLO localhost\r\nQUIT\r\n", stream_get_contents($accepted));
    }

    #[Test]
    public function reportsFailedConnection(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        static::assertIsResource($server);
        $port = self::port($server);
        fclose($server);

        $smtp = new Smtp(new ConnectionConfig('127.0.0.1', $port, Security::None, timeout: 1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot connect to 127.0.0.1:{$port}");

        $smtp->connect();
    }

    #[IgnoreDeprecations]
    #[Test]
    public function refusesToTalkWithoutConnection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No connection has been established to mail.example.com');

        (new Smtp('mail.example.com', config: ['ssl' => 'none'], connection: new InMemoryConnection()))->helo();
    }

    /**
     * STARTTLS with default settings verifies the server certificate, so a self-signed
     * certificate is refused.
     */
    #[Test]
    #[Group('slow')]
    public function verifiesServerCertificateByDefault(): void
    {
        $smtp = $this->startTlsSmtp(verifyPeer: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/^Cannot enable TLS with 127\.0\.0\.1:\d+: .*certificate verify failed/s',
        );

        $smtp->helo('localhost');
    }

    #[Test]
    #[Group('slow')]
    public function leavesNoPhpErrorBehindAfterFailedHandshake(): void
    {
        $smtp = $this->startTlsSmtp(verifyPeer: true);
        error_clear_last();

        try {
            $smtp->helo('localhost');
        } catch (RuntimeException) {
            static::assertNull(error_get_last());
            return;
        }

        static::fail('TLS started with an untrusted certificate');
    }

    #[Test]
    #[Group('slow')]
    public function upgradesToTlsWithStartTls(): void
    {
        $smtp = $this->startTlsSmtp(verifyPeer: false);
        $smtp->helo('localhost');

        static::assertTrue($smtp->isEncrypted());
    }

    /**
     * STARTTLS response injection (CVE-2011-0411 class): text sent after the 220 arrives
     * before encryption, so it must not be read as if it came over TLS.
     */
    #[Test]
    public function refusesTlsWhenServerSentDataAfterAgreeing(): void
    {
        $server = (new InMemoryConnection())->reply("220 mail.example.com ESMTP\r\n")
            ->expect("EHLO localhost\r\n")
            ->reply("250-mail.example.com\r\n250 STARTTLS\r\n")
            ->expect("STARTTLS\r\n")
            ->reply("220 2.0.0 Ready to start TLS\r\n250 injected reply\r\n")
            ->startTls();
        $smtp = new Smtp(new ConnectionConfig('mail.example.com'), connection: $server);
        $smtp->connect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server sent data before TLS was negotiated; refusing to continue');

        $smtp->helo('localhost');
    }

    /**
     * A plain session with a local server that has already queued its replies.
     *
     * @return array{Smtp, resource} The client, and the server's end of the connection.
     */
    private function plainSession(string $replies): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        static::assertIsResource($server);
        $this->sockets[] = $server;

        $smtp = new Smtp(new ConnectionConfig('127.0.0.1', self::port($server), Security::None, timeout: 2));
        $smtp->connect();
        $accepted = stream_socket_accept($server, timeout: 2);
        static::assertIsResource($accepted);
        $this->sockets[] = $accepted;
        stream_set_timeout($accepted, seconds: 2);
        fwrite($accepted, $replies);

        return [$smtp, $accepted];
    }

    private function startTlsSmtp(bool $verifyPeer): Smtp
    {
        $this->tlsServer = TlsServer::start('smtp');
        $smtp            = new Smtp(new ConnectionConfig(
            host: '127.0.0.1',
            port: $this->tlsServer->port,
            verifyPeer: $verifyPeer,
            timeout: self::TLS_TIMEOUT,
        ));
        $smtp->connect();

        return $smtp;
    }

    /**
     * @param resource $server
     */
    private static function port(mixed $server): int
    {
        $address = (string) stream_socket_get_name($server, remote: false);

        return (int) substr($address, strrpos($address, needle: ':') + 1);
    }
}
