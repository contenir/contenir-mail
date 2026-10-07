<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\ProtocolTrait;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\AbstractSocketOpener;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\TlsServer;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fgets;
use function fwrite;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;

use const STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
use const STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

#[CoversTrait(ProtocolTrait::class)]
#[Group('unit')]
final class ProtocolTraitTest extends TestCase
{
    private ?TlsServer $tlsServer = null;

    protected function tearDown(): void
    {
        $this->tlsServer?->stop();
    }

    /**
     * An object using the trait, with setupSocket() made public.
     */
    private static function protocol(): object
    {
        return new class {
            use ProtocolTrait;

            /**
             * @return resource
             */
            public function open(string $transport, string $host, ?int $port, int $timeout): mixed
            {
                return $this->setupSocket($transport, $host, $port, $timeout);
            }
        };
    }

    #[Test]
    public function offersOnlyTls12And13(): void
    {
        static::assertSame(
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            self::protocol()->getCryptoMethod(),
        );
    }

    #[Test]
    public function validatesCertificatesByDefault(): void
    {
        static::assertTrue(self::protocol()->validateCert());
    }

    #[Test]
    public function skipsCertificateValidationWhenAsked(): void
    {
        static::assertFalse(self::protocol()->setNoValidateCert(true)->validateCert());
    }

    #[Test]
    public function validatesCertificatesAgainWhenAsked(): void
    {
        static::assertTrue(self::protocol()->setNoValidateCert(true)->setNoValidateCert(false)->validateCert());
    }

    #[Test]
    public function opensAPlainSocket(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name   = (string) stream_socket_get_name($server, remote: false);
        $socket = self::protocol()
            ->open('tcp', '127.0.0.1', (int) substr($name, (int) strrpos($name, needle: ':') + 1), 5);
        $peer = stream_socket_accept($server, timeout: 5);
        fwrite($peer, data: "220 ready\r\n");
        $line = fgets($socket);
        fclose($server);

        static::assertSame("220 ready\r\n", $line);
    }

    #[Test]
    public function letsSubclassesOpenSockets(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name   = (string) stream_socket_get_name($server, remote: false);
        $opener = new class extends AbstractSocketOpener {
            /**
             * @return resource
             */
            public function open(int $port): mixed
            {
                return $this->setupSocket('tcp', '127.0.0.1', $port, 5);
            }
        };
        $socket = $opener->open((int) substr($name, (int) strrpos($name, needle: ':') + 1));
        fclose($server);

        static::assertIsResource($socket);
    }

    #[Test]
    public function failsWithoutAPort(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot connect to 127.0.0.1:0');

        self::protocol()->open('tcp', '127.0.0.1', null, 1);
    }

    #[Test]
    #[Group('slow')]
    public function opensATlsSocketWithoutValidationWhenAsked(): void
    {
        $this->tlsServer = TlsServer::start('implicit');
        $protocol        = self::protocol()->setNoValidateCert(true);

        static::assertSame("secure\r\n", fgets($protocol->open('ssl', '127.0.0.1', $this->tlsServer->port, 5)));
    }

    #[Test]
    #[Group('slow')]
    public function refusesAnUntrustedCertificateByDefault(): void
    {
        $this->tlsServer = TlsServer::start('implicit');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('certificate verify failed');

        self::protocol()->open('ssl', '127.0.0.1', $this->tlsServer->port, 5);
    }
}
