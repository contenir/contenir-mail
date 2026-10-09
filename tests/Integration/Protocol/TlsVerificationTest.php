<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Protocol;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\StreamConnection;
use Contenir\Mail\Tests\Integration\TestAsset\Servers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Certificates from the throwaway CA that start.sh creates, which PHPUnit
 * trusts through openssl.cafile: a good one, an expired one, one that signs
 * itself, and the good one reached by an address it does not name.
 */
#[CoversClass(StreamConnection::class)]
#[Group('integration')]
final class TlsVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        Servers::skipUnlessRunning();
    }

    /**
     * @return array<string, array{int, Security}>
     */
    public static function trustedProvider(): array
    {
        return [
            'TLS from the start' => [Servers::IMAPS, Security::Tls],
            'STARTTLS'           => [Servers::IMAP, Security::StartTls],
        ];
    }

    /**
     * @return array<string, array{string, int, Security, string}>
     */
    public static function untrustedProvider(): array
    {
        return [
            'expired, TLS from the start'      => [
                Servers::HOST,
                Servers::EXPIRED_IMAPS,
                Security::Tls,
                'certificate verify failed',
            ],
            'expired, STARTTLS'                => [
                Servers::HOST,
                Servers::EXPIRED_IMAP,
                Security::StartTls,
                'certificate verify failed',
            ],
            'self-signed, TLS from the start'  => [
                Servers::HOST,
                Servers::SELF_SIGNED_IMAPS,
                Security::Tls,
                'certificate verify failed',
            ],
            'self-signed, STARTTLS'            => [
                Servers::HOST,
                Servers::SELF_SIGNED_IMAP,
                Security::StartTls,
                'certificate verify failed',
            ],
            'another name, TLS from the start' => [
                Servers::IP_ADDRESS,
                Servers::IMAPS,
                Security::Tls,
                'did not match expected CN',
            ],
            'another name, STARTTLS'           => [
                Servers::IP_ADDRESS,
                Servers::IMAP,
                Security::StartTls,
                'did not match expected CN',
            ],
        ];
    }

    /**
     * @return array<string, array{int, Security}>
     */
    public static function unverifiedProvider(): array
    {
        return [
            'expired'     => [Servers::EXPIRED_IMAPS, Security::Tls],
            'self-signed' => [Servers::SELF_SIGNED_IMAP, Security::StartTls],
        ];
    }

    #[Test]
    #[DataProvider('trustedProvider')]
    public function acceptsCertificateFromTrustedCa(int $port, Security $security): void
    {
        $imap = new Imap(new ConnectionConfig(Servers::HOST, $port, $security));

        static::assertTrue($imap->login(Servers::USER, Servers::PASSWORD));
    }

    #[Test]
    #[DataProvider('untrustedProvider')]
    public function refusesUntrustedCertificate(string $host, int $port, Security $security, string $reason): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($reason);

        new Imap(new ConnectionConfig($host, $port, $security));
    }

    /**
     * Turning verification off is the documented way to reach such a server; it must still work.
     */
    #[Test]
    #[DataProvider('unverifiedProvider')]
    public function connectsToUntrustedCertificateWithVerificationOff(int $port, Security $security): void
    {
        $imap = new Imap(new ConnectionConfig(Servers::HOST, $port, $security, verifyPeer: false));

        static::assertTrue($imap->login(Servers::USER, Servers::PASSWORD));
    }
}
