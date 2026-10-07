<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Exception\InvalidArgumentException as MailInvalidArgumentException;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Constructing a session: ConnectionConfig and the laminas-mail positional arguments.
 */
#[CoversClass(Smtp::class)]
#[Group('unit')]
final class SmtpTest extends TestCase
{
    #[Test]
    public function keepsGivenConnectionConfig(): void
    {
        $config = new ConnectionConfig('mail.example.com', security: Security::Tls);

        static::assertSame($config, (new Smtp($config))->getConnectionConfig());
    }

    #[Test]
    public function keepsGivenAuthenticator(): void
    {
        $login = new Login('orders', 'secret');

        static::assertSame($login, (new Smtp(new ConnectionConfig(), authenticator: $login))->getAuthenticator());
    }

    #[Test]
    public function hasNoAuthenticatorByDefault(): void
    {
        static::assertNull((new Smtp())->getAuthenticator());
    }

    #[Test]
    public function requiresStartTlsByDefault(): void
    {
        static::assertSame(Security::StartTls, (new Smtp('mail.example.com'))->getConnectionConfig()->security);
    }

    #[Test]
    public function rejectsPortBesideConnectionConfig(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Give the port in the ConnectionConfig');

        new Smtp(new ConnectionConfig(), 25);
    }

    #[Test]
    public function rejectsLegacyKeyBesideConnectionConfig(): void
    {
        $this->expectException(MailInvalidArgumentException::class);
        $this->expectExceptionMessage('unknown option "ssl"; expected one of use_complete_quit, allow_insecure_auth');

        new Smtp(new ConnectionConfig(), config: ['ssl' => 'tls']);
    }

    #[Test]
    public function readsCompleteQuitBesideConnectionConfig(): void
    {
        static::assertFalse(
            (new Smtp(new ConnectionConfig(), config: ['use_complete_quit' => false]))->useCompleteQuit(),
        );
    }

    #[Test]
    public function refusesInsecureAuthByDefault(): void
    {
        static::assertFalse((new Smtp())->allowsInsecureAuth());
    }

    #[DataProvider('insecureAuthProvider')]
    #[Test]
    public function readsInsecureAuthSetting(ConnectionConfig|string $host): void
    {
        static::assertTrue((new Smtp($host, config: ['allow_insecure_auth' => true]))->allowsInsecureAuth());
    }

    /**
     * @return array<string, array{ConnectionConfig|string}>
     */
    public static function insecureAuthProvider(): array
    {
        return [
            'ConnectionConfig' => [new ConnectionConfig()],
            'host name'        => ['mail.example.com'],
        ];
    }

    #[Test]
    public function sendsQuitByDefault(): void
    {
        static::assertTrue((new Smtp())->useCompleteQuit());
    }

    #[Test]
    public function turnsCompleteQuitOff(): void
    {
        $smtp = new Smtp();
        $smtp->setUseCompleteQuit(false);

        static::assertFalse($smtp->useCompleteQuit());
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('legacySecurityProvider')]
    #[Test]
    public function readsLegacySslSetting(array $config, Security $expected): void
    {
        static::assertSame($expected, (new Smtp('mail.example.com', null, $config))->getConnectionConfig()->security);
    }

    #[Test]
    public function rejectsUnsupportedLegacySsl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('starttls is unsupported SSL type');

        new Smtp('mail.example.com', null, ['ssl' => 'starttls']);
    }

    #[Test]
    public function rejectsLegacySslBesideSecurity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Give either "ssl" or "security", not both');

        new Smtp('mail.example.com', null, ['ssl' => 'ssl', 'security' => 'tls']);
    }

    #[Test]
    public function rejectsNoValidateCertBesideVerifyPeer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Give either "novalidatecert" or "verify_peer", not both');

        new Smtp('mail.example.com', null, ['novalidatecert' => true, 'verify_peer' => true]);
    }

    #[Test]
    public function verifiesPeerByDefault(): void
    {
        static::assertTrue((new Smtp('mail.example.com'))->validateCert());
    }

    #[Test]
    public function turnsPeerVerificationOffWithNoValidateCert(): void
    {
        static::assertFalse((new Smtp('mail.example.com', null, ['novalidatecert' => true]))->validateCert());
    }

    #[Test]
    public function keepsLegacySecurityWhenOnlyNoValidateCertIsGiven(): void
    {
        $smtp = new Smtp('mail.example.com', null, ['novalidatecert' => false, 'security' => 'none']);

        static::assertSame(Security::None, $smtp->getConnectionConfig()->security);
    }

    #[Test]
    public function keepsVerifyPeerWhenOnlySslIsGiven(): void
    {
        $smtp = new Smtp('mail.example.com', null, ['ssl' => 'tls', 'verify_peer' => false]);

        static::assertFalse($smtp->getConnectionConfig()->verifyPeer);
    }

    #[Test]
    public function keepsOtherConnectionSettingsWithLegacySsl(): void
    {
        $smtp = new Smtp('mail.example.com', 2525, ['ssl' => 'tls', 'timeout' => 5]);

        static::assertEquals(
            new ConnectionConfig('mail.example.com', 2525, Security::StartTls, true, 5),
            $smtp->getConnectionConfig(),
        );
    }

    #[Test]
    public function readsHostAndPortFromLeadingArray(): void
    {
        $smtp = new Smtp(['host' => 'mail.example.com', 'port' => 2525], config: ['ssl' => 'ssl']);

        static::assertEquals(
            new ConnectionConfig('mail.example.com', 2525, Security::Tls),
            $smtp->getConnectionConfig(),
        );
    }

    #[Test]
    public function letsPositionalHostWinOverConfigHost(): void
    {
        $smtp = new Smtp('mail.example.com', null, ['host' => 'other.example.com']);

        static::assertSame('mail.example.com', $smtp->getConnectionConfig()->host);
    }

    #[Test]
    public function readsCompleteQuitFromLegacyConfig(): void
    {
        static::assertFalse((new Smtp('mail.example.com', null, ['use_complete_quit' => false]))->useCompleteQuit());
    }

    #[Test]
    public function rejectsUnknownLegacyKey(): void
    {
        $this->expectException(MailInvalidArgumentException::class);
        $this->expectExceptionMessage('unknown option "username"');

        new Smtp('mail.example.com', null, ['username' => 'orders']);
    }

    #[Test]
    public function rejectsInvalidHostName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The input does not match the expected structure for a DNS hostname');

        new Smtp('invalid host name');
    }

    /**
     * @return array<string, array{array<string, mixed>, Security}>
     */
    public static function legacySecurityProvider(): array
    {
        return [
            'omitted'          => [[], Security::StartTls],
            'ssl'              => [['ssl' => 'ssl'], Security::Tls],
            'SSL'              => [['ssl' => 'SSL'], Security::Tls],
            'tls'              => [['ssl' => 'tls'], Security::StartTls],
            'none'             => [['ssl' => 'none'], Security::None],
            'empty string'     => [['ssl' => ''], Security::None],
            'false'            => [['ssl' => false], Security::None],
            'null'             => [['ssl' => null], Security::StartTls],
            'security setting' => [['security' => 'tls'], Security::Tls],
        ];
    }
}
