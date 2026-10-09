<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use ArrayObject;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\ErrorCapture;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Exception\TimeoutException;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\StreamConnection;
use Contenir\Mail\Protocol\TlsConfig;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\TlsServer;
use Contenir\Mail\Tests\Unit\TestAsset\RecordingWriteStream;
use Contenir\Mail\Tests\Unit\TestAsset\ShortReadStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fgets;
use function fopen;
use function fwrite;
use function is_resource;
use function str_repeat;
use function stream_get_contents;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strrpos;
use function substr;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/**
 * Exercises the stream connection over local socket pairs and loopback
 * sockets; nothing leaves the machine.
 */
#[CoversClass(StreamConnection::class)]
#[UsesClass(ErrorCapture::class)]
#[Group('unit')]
final class StreamConnectionTest extends TestCase
{
    /** @var list<resource> */
    private array $resources = [];

    private ?TlsServer $tlsServer = null;

    protected function tearDown(): void
    {
        foreach ($this->resources as $resource) {
            if (! is_resource($resource)) {
                continue;
            }

            fclose($resource);
        }

        $this->tlsServer?->stop();
    }

    #[Test]
    public function writesAllTheBytes(): void
    {
        [$connection, $peer] = $this->pair();

        $connection->write("NOOP\r\n");

        static::assertSame("NOOP\r\n", fgets($peer));
    }

    #[Test]
    public function readsALine(): void
    {
        [$connection, $peer] = $this->pair();
        fwrite($peer, data: "+OK ready\r\nnext\r\n");

        static::assertSame("+OK ready\r\n", $connection->readLine(100));
    }

    #[Test]
    public function readsALongLineInPiecesOfTheMaximumLength(): void
    {
        [$connection, $peer] = $this->pair();
        fwrite($peer, data: "abcdef\r\n");

        static::assertSame('abcd', $connection->readLine(4));
    }

    #[Test]
    public function readsExactlyTheBytesAsked(): void
    {
        [$connection, $peer] = $this->pair();
        fwrite($peer, data: "hello world\r\n");

        static::assertSame('hello', $connection->read(5));
    }

    #[Test]
    public function readsBytesThatArriveInSeveralPackets(): void
    {
        [$connection, $peer] = $this->pair();
        fwrite($peer, data: 'hel');
        $connection->setTimeout(1);
        $first = $connection->read(2);
        fwrite($peer, data: 'lo');

        static::assertSame('hello', $first . $connection->read(3));
    }

    #[Test]
    public function readsNothingForAZeroLength(): void
    {
        [$connection] = $this->pair();

        static::assertSame('', $connection->read(0));
    }

    #[Test]
    public function failsToReadALineOnceThePeerHasClosed(): void
    {
        [$connection, $peer] = $this->pair();
        fclose($peer);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read from the server: the connection is closed');

        $connection->readLine(100);
    }

    #[Test]
    public function failsToReadBytesThatNeverArriveBeforeThePeerCloses(): void
    {
        [$connection, $peer] = $this->pair();
        fwrite($peer, data: 'abc');
        fclose($peer);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the connection is closed');

        $connection->read(10);
    }

    #[Test]
    #[Group('slow')]
    public function timesOutWaitingForALine(): void
    {
        [$connection] = $this->pair();
        $connection->setTimeout(1);

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('the server has timed out');

        $connection->readLine(100);
    }

    #[Test]
    #[Group('slow')]
    public function timesOutWaitingForBytes(): void
    {
        [$connection] = $this->pair();
        $connection->setTimeout(1);

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('the server has timed out');

        $connection->read(1);
    }

    #[Test]
    public function failsToWriteOnceThePeerHasClosed(): void
    {
        [$connection, $peer] = $this->pair();
        fclose($peer);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot write to the server: fwrite(): Send of 6 bytes failed');

        $connection->write("NOOP\r\n");
    }

    #[Test]
    public function writesToAStreamThatCannotBeWaitedOn(): void
    {
        $stream = $this->track(fopen('php://memory', mode: 'w+'));
        StreamConnection::fromStream($stream)->write('abc');

        static::assertSame('abc', stream_get_contents($stream, offset: 0));
    }

    #[Test]
    public function writesALargeStringInSlicesOf64Kilobytes(): void
    {
        $writes = new ArrayObject();
        StreamConnection::fromStream(RecordingWriteStream::open($writes, accept: 1 << 20))->write(str_repeat(
            'a',
            (65_536 * 2) + 1,
        ));

        static::assertSame([65_536, 65_536, 1], $writes->getArrayCopy());
    }

    #[Test]
    public function writesOnFromWhereAShortWriteStopped(): void
    {
        $writes = new ArrayObject();
        StreamConnection::fromStream(RecordingWriteStream::open($writes, accept: 40_000))->write(str_repeat(
            'a',
            times: 100_000,
        ));

        static::assertSame([65_536, 60_000, 20_000], $writes->getArrayCopy());
    }

    #[Test]
    public function reportsBytesTheServerHasSent(): void
    {
        [$connection, $peer] = $this->pair();
        fwrite($peer, data: "* 3 EXISTS\r\n");

        static::assertTrue($connection->waitUntilReadable(1));
    }

    #[Test]
    public function reportsAQuietServerWithoutFailing(): void
    {
        [$connection] = $this->pair();

        static::assertSame([false, true], [$connection->waitUntilReadable(0), $connection->isConnected()]);
    }

    #[Test]
    public function reportsBytesAlreadyBufferedFromAnEarlierRead(): void
    {
        [$connection, $peer] = $this->pair();
        fwrite($peer, data: "* 3 EXISTS\r\n* 4 EXISTS\r\n");
        $connection->readLine(100);

        static::assertTrue($connection->waitUntilReadable(0));
    }

    #[Test]
    public function reportsAClosedConnectionForTheNextReadToFail(): void
    {
        [$connection, $peer] = $this->pair();
        fclose($peer);

        static::assertTrue($connection->waitUntilReadable(0));
    }

    #[Test]
    public function waitsForNothingOnAStreamThatCannotBeWaitedOn(): void
    {
        $connection = StreamConnection::fromStream($this->track(fopen('php://memory', mode: 'rb')));

        static::assertTrue($connection->waitUntilReadable(0));
    }

    #[Test]
    public function refusesToWaitBeforeOpening(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No connection has been established to the server');

        (new StreamConnection())->waitUntilReadable(1);
    }

    #[Test]
    public function refusesTlsWhenPlainTextBytesAreBuffered(): void
    {
        [$connection, $peer] = $this->pair();
        fwrite($peer, data: "TAG2 OK begin TLS\r\nTAG3 OK injected\r\n");
        $connection->readLine(100);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the server sent data before TLS was negotiated; refusing to continue');

        $connection->enableTls();
    }

    #[Test]
    public function failsWhenThePeerDoesNotSpeakTls(): void
    {
        [$connection, $peer] = $this->pair();
        fwrite($peer, data: "this is not a TLS handshake\r\n");
        fclose($peer);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot enable TLS with the server');

        $connection->enableTls();
    }

    #[Test]
    public function isConnectedWhileTheStreamIsOpen(): void
    {
        [$connection] = $this->pair();

        static::assertTrue($connection->isConnected());
    }

    #[Test]
    public function isNotConnectedOnceClosed(): void
    {
        [$connection] = $this->pair();

        $connection->close();

        static::assertFalse($connection->isConnected());
    }

    #[Test]
    public function closesTheStream(): void
    {
        [$connection, , $stream] = $this->pair();

        $connection->close();

        static::assertFalse(is_resource($stream));
    }

    #[Test]
    public function closesOnlyOnce(): void
    {
        [$connection] = $this->pair();
        $connection->close();
        $connection->close();

        static::assertFalse($connection->isConnected());
    }

    #[Test]
    public function isNotConnectedBeforeOpening(): void
    {
        static::assertFalse((new StreamConnection())->isConnected());
    }

    #[Test]
    public function refusesToWorkBeforeOpening(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No connection has been established to the server');

        (new StreamConnection())->write('x');
    }

    #[Test]
    public function namesThePeerInErrors(): void
    {
        $connection = StreamConnection::fromStream($this->track(fopen('php://memory', mode: 'rb')), 'imap.example.com');
        $connection->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No connection has been established to imap.example.com');

        $connection->setTimeout(1);
    }

    #[Test]
    public function refusesSomethingOtherThanAStream(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected an open stream');

        StreamConnection::fromStream('tcp://example.com:143');
    }

    #[Test]
    public function refusesAClosedStream(): void
    {
        $stream = fopen('php://memory', mode: 'rb');
        fclose($stream);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected an open stream');

        StreamConnection::fromStream($stream);
    }

    #[Test]
    public function connectsToALoopbackServer(): void
    {
        [$server, $port] = $this->listen();
        $connection = new StreamConnection();
        $connection->open(new ConnectionConfig(
            host: '127.0.0.1',
            security: Security::None,
            timeout: 5,
        ), $port);
        $accepted = $this->track(stream_socket_accept($server, timeout: 5));
        fwrite($accepted, data: "* OK hello\r\n");

        static::assertSame("* OK hello\r\n", $connection->readLine(100));
    }

    #[Test]
    #[Group('slow')]
    public function waitsAtMostTheConfiguredTimeoutForEachRead(): void
    {
        [$server, $port] = $this->listen();
        $connection = new StreamConnection();
        $connection->open(new ConnectionConfig(
            host: '127.0.0.1',
            security: Security::None,
            timeout: 1,
        ), $port);
        $this->track(stream_socket_accept($server, timeout: 5));

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage("127.0.0.1:{$port} has timed out");

        $connection->readLine(100);
    }

    #[Test]
    public function closesThePreviousConnectionWhenOpeningAgain(): void
    {
        [$server, $port] = $this->listen();
        $connection = new StreamConnection();
        $config     = new ConnectionConfig(
            host: '127.0.0.1',
            security: Security::None,
            timeout: 5,
        );
        $connection->open($config, $port);
        $first = $this->track(stream_socket_accept($server, timeout: 5));
        $connection->open($config, $port);

        static::assertFalse(fgets($first));
    }

    #[Test]
    public function failsToConnectWhereNothingListens(): void
    {
        [$server, $port] = $this->listen();
        fclose($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot connect to 127.0.0.1:{$port}: stream_socket_client(): Unable to connect");

        (new StreamConnection())->open(new ConnectionConfig(
            host: '127.0.0.1',
            security: Security::None,
        ), $port);
    }

    /**
     * Nothing on these ports offers TLS from the start, so the connection fails whether or not
     * a server listens there.
     */
    #[DataProvider('startTlsPortProvider')]
    #[Test]
    public function suggestsStartTlsWhenTlsFromTheStartFailsOnAStartTlsPort(int $port): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(
            "/^Cannot connect to 127\\.0\\.0\\.1:{$port}: .+; port {$port} usually expects STARTTLS: "
                . 'if this server does, set security to "starttls"$/s',
        );

        (new StreamConnection())->open(new ConnectionConfig(
            host: '127.0.0.1',
            security: Security::Tls,
            timeout: 2,
        ), $port);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function startTlsPortProvider(): array
    {
        return [
            'SMTP'       => [25],
            'POP3'       => [110],
            'IMAP'       => [143],
            'submission' => [587],
        ];
    }

    /**
     * A plain connection may succeed where a mail server listens, which needs no hint either.
     */
    #[Test]
    public function suggestsNothingForAPlainConnectionOnAStartTlsPort(): void
    {
        $connection = new StreamConnection();
        try {
            $connection->open(new ConnectionConfig(
                host: '127.0.0.1',
                security: Security::None,
                timeout: 2,
            ), 587);
        } catch (RuntimeException $e) {
            static::assertStringNotContainsString('STARTTLS', $e->getMessage());

            return;
        }

        $connection->close();
        static::assertFalse($connection->isConnected());
    }

    #[Test]
    public function suggestsNothingForTlsFromTheStartOnAnotherPort(): void
    {
        [$server, $port] = $this->listen();
        fclose($server);

        try {
            (new StreamConnection())->open(new ConnectionConfig(
                host: '127.0.0.1',
                security: Security::Tls,
                timeout: 2,
            ), $port);
        } catch (RuntimeException $e) {
            static::assertStringNotContainsString('STARTTLS', $e->getMessage());

            return;
        }

        static::fail('Nothing listens on the closed port');
    }

    #[Test]
    public function bracketsAnIpv6Address(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot connect to [::1]:1:');

        (new StreamConnection())->open(new ConnectionConfig(
            host: '::1',
            security: Security::None,
            timeout: 1,
        ), 1);
    }

    #[Test]
    #[Group('slow')]
    public function connectsWithImplicitTlsWhenVerificationIsOff(): void
    {
        $this->tlsServer = TlsServer::start('implicit');
        $connection      = new StreamConnection();
        $connection->open(
            new ConnectionConfig(
                host: '127.0.0.1',
                security: Security::Tls,
                verifyPeer: false,
                timeout: 5,
            ),
            $this->tlsServer->port,
        );

        static::assertSame("secure\r\n", $connection->readLine(100));
    }

    #[Test]
    #[Group('slow')]
    public function refusesAnUntrustedCertificateByDefault(): void
    {
        $this->tlsServer = TlsServer::start('implicit');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('certificate verify failed');

        (new StreamConnection())->open(
            new ConnectionConfig(
                host: '127.0.0.1',
                security: Security::Tls,
                timeout: 5,
            ),
            $this->tlsServer->port,
        );
    }

    #[Test]
    #[Group('slow')]
    public function upgradesAPlainConnectionWithStartTls(): void
    {
        $this->tlsServer = TlsServer::start('starttls');
        $connection      = $this->startTlsClient(verifyPeer: false);

        $connection->enableTls();
        $connection->write("NOOP\r\n");

        static::assertSame("secure\r\n", $connection->readLine(100));
    }

    #[Test]
    #[Group('slow')]
    public function refusesAnUntrustedCertificateAfterStartTls(): void
    {
        $this->tlsServer = TlsServer::start('starttls');
        $connection      = $this->startTlsClient(verifyPeer: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('certificate verify failed');

        $connection->enableTls();
    }

    /**
     * The server's certificate is self-signed and issued to "localhost", while the
     * client connects to 127.0.0.1: allowSelfSigned and peerName together let it
     * through with verification on, and each is needed (contenir/contenir-mail#16).
     */
    #[Test]
    #[Group('slow')]
    public function acceptsASelfSignedCertificateForTheNamedPeerWhenAllowed(): void
    {
        $this->tlsServer = TlsServer::start('implicit');
        $connection      = new StreamConnection();
        $connection->open(
            new ConnectionConfig(
                host: '127.0.0.1',
                security: Security::Tls,
                timeout: 5,
                tls: new TlsConfig(
                    peerName: 'localhost',
                    allowSelfSigned: true,
                ),
            ),
            $this->tlsServer->port,
        );

        static::assertSame("secure\r\n", $connection->readLine(100));
    }

    #[Test]
    #[Group('slow')]
    public function stillChecksThePeerNameOfASelfSignedCertificate(): void
    {
        $this->tlsServer = TlsServer::start('implicit');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not match expected CN=`127.0.0.1\'');

        (new StreamConnection())->open(
            new ConnectionConfig(
                host: '127.0.0.1',
                security: Security::Tls,
                timeout: 5,
                tls: new TlsConfig(allowSelfSigned: true),
            ),
            $this->tlsServer->port,
        );
    }

    #[Test]
    #[Group('slow')]
    public function trustsTheCertificateAuthorityGiven(): void
    {
        $this->tlsServer = TlsServer::start('starttls');
        $connection      = new StreamConnection();
        $connection->open(
            new ConnectionConfig(
                host: '127.0.0.1',
                security: Security::StartTls,
                timeout: 5,
                tls: new TlsConfig(
                    caFile: $this->tlsServer->certificate,
                    peerName: 'localhost',
                ),
            ),
            $this->tlsServer->port,
        );
        $connection->readLine(100);
        $connection->write("STARTTLS\r\n");
        $connection->enableTls();
        $connection->write("NOOP\r\n");

        static::assertSame("secure\r\n", $connection->readLine(100));
    }

    private function startTlsClient(bool $verifyPeer): StreamConnection
    {
        $connection = new StreamConnection();
        $connection->open(
            new ConnectionConfig(
                host: '127.0.0.1',
                security: Security::StartTls,
                verifyPeer: $verifyPeer,
                timeout: 5,
            ),
            $this->tlsServer->port ?? 0,
        );
        $connection->readLine(100);
        $connection->write("STARTTLS\r\n");

        return $connection;
    }

    /**
     * @return array{StreamConnection, resource, resource}
     */
    private function pair(): array
    {
        [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->track($client);
        $this->track($peer);

        return [StreamConnection::fromStream($client), $peer, $client];
    }

    /**
     * @return array{resource, int}
     */
    private function listen(): array
    {
        $server = $this->track(stream_socket_server('tcp://127.0.0.1:0'));
        $name   = (string) stream_socket_get_name($server, remote: false);

        return [$server, (int) substr($name, (int) strrpos($name, needle: ':') + 1)];
    }

    /**
     * @param resource|false $resource
     * @return resource
     */
    private function track(mixed $resource): mixed
    {
        static::assertIsResource($resource);
        $this->resources[] = $resource;

        return $resource;
    }

    #[Test]
    public function closesAnAdoptedStreamWhenOpeningAnother(): void
    {
        [$connection, , $stream] = $this->pair();
        [$server, $port] = $this->listen();
        $connection->open(new ConnectionConfig(
            host: '127.0.0.1',
            security: Security::None,
            timeout: 5,
        ), $port);
        $this->track(stream_socket_accept($server, timeout: 5));

        static::assertFalse(is_resource($stream));
    }

    #[Test]
    #[Group('slow')]
    public function timesOutWhenThePeerStopsReading(): void
    {
        [$connection] = $this->pair();
        $connection->setTimeout(1);

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('the server has timed out');

        $connection->write(str_repeat('x', times: 10_000_000));
    }

    #[Test]
    public function readsNoMoreThanAskedFromAStreamThatReturnsShortReads(): void
    {
        $connection = StreamConnection::fromStream($this->track(ShortReadStream::open('abcdefgh', readSize: 3)));

        static::assertSame('abcd', $connection->read(4));
    }

    #[Test]
    public function reportsAClosedConnectionWithoutAWarning(): void
    {
        [$connection, $peer] = $this->pair();
        fclose($peer);
        $message = '';
        try {
            $connection->readLine(100);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        static::assertSame('Cannot read from the server: the connection is closed', $message);
    }

    #[Test]
    #[Group('slow')]
    public function waitsForReadsAgainAfterAWriteTimesOut(): void
    {
        [$connection] = $this->pair();
        $connection->setTimeout(1);
        try {
            $connection->write(str_repeat('x', times: 10_000_000));
        } catch (TimeoutException) {
            $connection->setTimeout(1);
        }

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('the server has timed out');

        $connection->readLine(100);
    }

    #[Test]
    public function failsToReadFromAStreamThatRefusesReads(): void
    {
        $connection = StreamConnection::fromStream($this->track(fopen('php://stdout', mode: 'wb')));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read from the server: the connection is closed');

        $connection->read(1);
    }
}
