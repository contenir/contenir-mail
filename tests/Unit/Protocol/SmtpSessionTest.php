<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp;
use Contenir\Mail\Protocol\Smtp\Auth\AuthenticatorInterface;
use Contenir\Mail\Protocol\Smtp\Auth\ChannelInterface;
use Contenir\Mail\Protocol\Smtp\Auth\CramMd5;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Protocol\Smtp\Auth\Plain;
use Contenir\Mail\Tests\Unit\TestAsset\ScramVector;
use Contenir\Mail\Tests\Unit\TestAsset\SmtpServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

use function base64_encode;
use function strrpos;
use function substr;

/**
 * Opening a session: greeting, EHLO, STARTTLS and AUTH.
 */
#[CoversClass(Smtp::class)]
#[Group('unit')]
final class SmtpSessionTest extends TestCase
{
    private const string AUTH_VALUE = 'correct horse battery staple';

    #[DataProvider('securityProvider')]
    #[Test]
    public function opensConnectionWithConfiguredSecurity(Security $security): void
    {
        $server = new SmtpServer();
        self::smtp($security, server: $server)->connect();

        static::assertSame($security, $server->openedWith()?->security);
    }

    #[Test]
    public function opensConnectionWithConfiguredTimeout(): void
    {
        $server = new SmtpServer();
        (new Smtp(new ConnectionConfig('mail.example.com', timeout: 7), connection: $server))->connect();

        static::assertSame(7, $server->openedWith()?->timeout);
    }

    #[DataProvider('portProvider')]
    #[Test]
    public function connectsToStandardPortForSecurity(Security $security, int $expected): void
    {
        $server = new SmtpServer();
        self::smtp($security, server: $server)->connect();

        static::assertSame($expected, $server->openedPort());
    }

    /**
     * The laminas-mail form without "ssl" requires STARTTLS, so it uses the submission port.
     */
    #[IgnoreDeprecations]
    #[Test]
    public function connectsToSubmissionPortWithoutPortOrSecurity(): void
    {
        $server = new SmtpServer();
        (new Smtp('mail.example.com', connection: $server))->connect();

        static::assertSame(587, $server->openedPort());
    }

    #[Test]
    public function connectsToConfiguredPort(): void
    {
        $server = new SmtpServer();
        (new Smtp(new ConnectionConfig('mail.example.com', 2525), connection: $server))->connect();

        static::assertSame(2525, $server->openedPort());
    }

    #[Test]
    public function verifiesServerCertificateByDefault(): void
    {
        $server = new SmtpServer();
        self::smtp(Security::Tls, server: $server)->connect();

        static::assertTrue($server->openedWith()?->verifyPeer);
    }

    #[Test]
    public function skipsCertificateVerificationWhenConfigured(): void
    {
        $server = new SmtpServer();
        $smtp   = new Smtp(
            new ConnectionConfig('mail.example.com', security: Security::Tls, verifyPeer: false),
            connection: $server,
        );
        $smtp->connect();

        static::assertFalse($server->openedWith()?->verifyPeer);
    }

    #[IgnoreDeprecations]
    #[Test]
    public function skipsCertificateVerificationWhenToldAfterConstruction(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::Tls, server: $server);
        $smtp->setNoValidateCert(true);
        $smtp->connect();

        static::assertFalse($server->openedWith()?->verifyPeer);
    }

    #[Test]
    public function upgradesWithStartTlsAndRepeatsEhlo(): void
    {
        $server = new SmtpServer();
        $_smtp  = self::session(Security::StartTls, server: $server);

        static::assertSame(['EHLO localhost', 'STARTTLS', 'EHLO localhost'], $server->sentLines());
    }

    #[Test]
    public function startsTlsOnTheConnectionAfterStartTls(): void
    {
        $server = new SmtpServer();
        self::session(Security::StartTls, server: $server);

        static::assertTrue($server->tlsStarted());
    }

    #[DataProvider('encryptionProvider')]
    #[Test]
    public function reportsWhetherSessionIsEncrypted(Security $security, bool $expected): void
    {
        static::assertSame($expected, self::session($security)->isEncrypted());
    }

    #[Test]
    public function isNotEncryptedBeforeConnecting(): void
    {
        static::assertFalse(self::smtp(Security::Tls)->isEncrypted());
    }

    #[Test]
    public function isNotEncryptedAfterDisconnecting(): void
    {
        $smtp = self::session(Security::Tls);
        $smtp->disconnect();

        static::assertFalse($smtp->isEncrypted());
    }

    #[DataProvider('noStartTlsProvider')]
    #[Test]
    public function sendsNoStartTlsUnlessRequired(Security $security): void
    {
        $server = new SmtpServer();
        $_smtp  = self::session($security, server: $server);

        static::assertSame(['EHLO localhost'], $server->sentLines());
    }

    /**
     * A server, or an attacker between client and server, that hides STARTTLS must not
     * get a plain-text session.
     */
    #[Test]
    public function refusesServerThatDoesNotOfferStartTls(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::StartTls, server: $server);
        $server->setCapabilities('AUTH PLAIN');
        $smtp->connect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The server does not offer STARTTLS; set security to "tls" for TLS from the start, '
                . 'or to "none" explicitly to send without encryption',
        );

        $smtp->helo('localhost');
    }

    #[Test]
    public function sendsNothingMoreToServerWithoutStartTls(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::StartTls, server: $server);
        $server->setCapabilities('AUTH PLAIN');
        $smtp->connect();

        try {
            $smtp->helo('localhost');
        } catch (RuntimeException) {
            static::assertSame(['EHLO localhost'], $server->sentLines());
            return;
        }

        static::fail('The session continued without STARTTLS');
    }

    #[Test]
    public function refusesSessionWhenServerRefusesStartTls(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::StartTls, server: $server);
        $server->reply('STARTTLS', '454 4.7.0 TLS not available');
        $smtp->connect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server refused STARTTLS: 4.7.0 TLS not available');
        $this->expectExceptionCode(454);

        $smtp->helo('localhost');
    }

    #[Test]
    public function startsNoSessionWhenTlsFails(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::StartTls, server: $server);
        $server->failTls('Unable to start TLS: handshake failed');
        $smtp->connect();

        try {
            $smtp->helo('localhost');
        } catch (RuntimeException) {
            static::assertFalse($smtp->hasSession());
            return;
        }

        static::fail('The session started without TLS');
    }

    /**
     * Capabilities heard before TLS could have been altered in transit (RFC 3207 section 4.2).
     */
    #[Test]
    public function discardsCapabilitiesFromBeforeStartTls(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::StartTls, server: $server);
        $server->reply('EHLO', '250-mail.example.com', '250-STARTTLS', '250 AUTH PLAIN');
        $server->setCapabilities('SIZE 5000');
        $smtp->connect();
        $smtp->helo('localhost');

        static::assertSame(['SIZE' => '5000'], $smtp->getCapabilities());
    }

    #[Test]
    public function readsCapabilitiesFromEhlo(): void
    {
        static::assertSame(
            [
                'STARTTLS' => '',
                'AUTH'     => 'PLAIN LOGIN CRAM-MD5 XOAUTH2',
                'SIZE'     => '1000',
                '8BITMIME' => '',
                'SMTPUTF8' => '',
            ],
            self::session(Security::None)->getCapabilities(),
        );
    }

    #[Test]
    public function readsCapabilitiesInOldAuthSyntax(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::None, server: $server);
        $server->setCapabilities('AUTH=login plain');
        $smtp->connect();
        $smtp->helo('localhost');

        static::assertSame(['AUTH' => 'LOGIN PLAIN'], $smtp->getCapabilities());
    }

    #[Test]
    public function skipsEmptyCapabilityLines(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::None, server: $server);
        $server->setCapabilities('', 'PIPELINING');
        $smtp->connect();
        $smtp->helo('localhost');

        static::assertSame(['PIPELINING' => ''], $smtp->getCapabilities());
    }

    #[Test]
    public function readsLowerCaseKeywordsAndSpacedParameters(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::None, server: $server);
        $server->setCapabilities('size   2000');
        $smtp->connect();
        $smtp->helo('localhost');

        static::assertSame(['SIZE' => '2000'], $smtp->getCapabilities());
    }

    #[Test]
    public function looksUpCapabilitiesInAnyCase(): void
    {
        static::assertTrue(self::session(Security::None)->hasCapability('smtputf8'));
    }

    #[Test]
    public function reportsMissingCapability(): void
    {
        static::assertFalse(self::session(Security::None)->hasCapability('PIPELINING'));
    }

    #[Test]
    public function fallsBackToHeloWhenEhloIsRefused(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::None, server: $server);
        $server->reply('EHLO', '502 5.5.1 Command not implemented');
        $smtp->connect();
        $smtp->helo('localhost');

        static::assertSame(['EHLO localhost', 'HELO localhost'], $server->sentLines());
    }

    #[Test]
    public function waitsFiveMinutesForHeloReply(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::None, server: $server);
        $server->reply('EHLO', '502 5.5.1 Command not implemented');
        $smtp->connect();
        $smtp->helo('localhost');

        static::assertSame(300, $server->timeoutFor('HELO localhost'));
    }

    #[Test]
    public function refusesSessionWhenServerRefusesHeloToo(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::None, server: $server);
        $server->reply('EHLO', '502 5.5.1 Command not implemented');
        $server->reply('HELO', '501 5.5.4 Invalid domain');
        $smtp->connect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('5.5.4 Invalid domain');

        $smtp->helo('localhost');
    }

    #[Test]
    public function reportsConnection(): void
    {
        static::assertTrue(self::smtp(Security::None)->connect());
    }

    #[Test]
    public function hasNoCapabilitiesAfterHelo(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::None, server: $server);
        $server->reply('EHLO', '502 5.5.1 Command not implemented');
        $smtp->connect();
        $smtp->helo('localhost');

        static::assertSame([], $smtp->getCapabilities());
    }

    #[Test]
    public function startsSession(): void
    {
        static::assertTrue(self::session(Security::None)->hasSession());
    }

    #[Test]
    public function hasNoSessionBeforeHelo(): void
    {
        static::assertFalse(self::smtp(Security::None)->hasSession());
    }

    #[Test]
    public function refusesSecondHelo(): void
    {
        $smtp = self::session(Security::None);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot issue HELO to existing session');

        $smtp->helo('localhost');
    }

    #[Test]
    public function refusesInvalidHeloName(): void
    {
        $smtp = self::smtp(Security::None);
        $smtp->connect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The input does not match the expected structure for a DNS hostname');

        $smtp->helo("invalid\r\nhost name");
    }

    #[Test]
    public function refusesUnwelcomingGreeting(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::None, server: $server);
        $server->setGreeting('554 5.3.2 No service');
        $smtp->connect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('5.3.2 No service');
        $this->expectExceptionCode(554);

        $smtp->helo('localhost');
    }

    #[Test]
    public function authenticatesAfterStartTls(): void
    {
        $server = new SmtpServer();
        $_smtp  = self::session(Security::StartTls, new Login('orders', self::AUTH_VALUE), server: $server);

        static::assertSame(
            [
                'EHLO localhost',
                'STARTTLS',
                'EHLO localhost',
                'AUTH LOGIN',
                base64_encode('orders'),
                base64_encode(self::AUTH_VALUE),
            ],
            $server->sentLines(),
        );
    }

    #[Test]
    public function reportsAuthentication(): void
    {
        static::assertTrue(self::session(Security::Tls, new Login('orders', self::AUTH_VALUE))->isAuthenticated());
    }

    #[Test]
    public function isNotAuthenticatedWithoutAuthenticator(): void
    {
        static::assertFalse(self::session(Security::Tls)->isAuthenticated());
    }

    #[Test]
    public function sendsNoAuthWithoutAuthenticator(): void
    {
        $server = new SmtpServer();
        $_smtp  = self::session(Security::Tls, server: $server);

        static::assertSame(['EHLO localhost'], $server->sentLines());
    }

    #[DataProvider('secretProvider')]
    #[Test]
    public function keepsCredentialsOutOfSessionLog(#[SensitiveParameter] string $secret): void
    {
        $smtp = self::session(Security::Tls, new Login('orders', self::AUTH_VALUE));

        static::assertStringNotContainsString($secret, $smtp->getLog());
    }

    /**
     * The RFC 7677 test vector over SMTP: server-final arrives in a 334 and is answered with an empty line.
     */
    #[Test]
    public function authenticatesWithScramSha256(): void
    {
        $server = new SmtpServer();
        $server->setCapabilities('AUTH SCRAM-SHA-256');
        $server->reply(
            ScramVector::b64(ScramVector::CLIENT_FIRST),
            '334 ' . ScramVector::b64(ScramVector::SERVER_FIRST),
        );
        $server->reply(
            ScramVector::b64(ScramVector::CLIENT_FINAL),
            '334 ' . ScramVector::b64(ScramVector::SERVER_FINAL),
        );
        $smtp = self::session(Security::Tls, ScramVector::authenticator(), $server);

        static::assertSame(
            [
                true,
                "AUTH SCRAM-SHA-256\r\n334 \r\n"
                    . Smtp::HIDDEN_LINE
                    . "\r\n334 "
                    . ScramVector::b64(ScramVector::SERVER_FIRST)
                    . "\r\n"
                    . Smtp::HIDDEN_LINE
                    . "\r\n334 "
                    . ScramVector::b64(ScramVector::SERVER_FINAL)
                    . "\r\n\r\n235 2.7.0 Accepted\r\n",
            ],
            [$smtp->isAuthenticated(), substr($smtp->getLog(), (int) strrpos($smtp->getLog(), needle: "\nAUTH ") + 1)],
        );
    }

    #[Test]
    public function marksHiddenCredentialsInSessionLog(): void
    {
        $smtp = self::session(Security::Tls, new Plain('orders', self::AUTH_VALUE));

        static::assertStringContainsString("AUTH PLAIN\r\n334 \r\n" . Smtp::HIDDEN_LINE . "\r\n235", $smtp->getLog());
    }

    #[Test]
    public function keepsCredentialsOutOfLastRequest(): void
    {
        static::assertSame(
            Smtp::HIDDEN_LINE,
            self::session(Security::Tls, new Plain('orders', self::AUTH_VALUE))->getRequest(),
        );
    }

    #[Test]
    public function logsCommandsAfterAuthentication(): void
    {
        $smtp = self::session(Security::Tls, new Plain('orders', self::AUTH_VALUE));
        $smtp->noop();

        static::assertSame('NOOP', $smtp->getRequest());
    }

    /**
     * Credentials must never cross a connection an eavesdropper can read.
     */
    #[Test]
    public function refusesToAuthenticateOverUnencryptedConnection(): void
    {
        $smtp = self::smtp(Security::None, new Plain('orders', self::AUTH_VALUE));
        $smtp->connect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to send credentials over an unencrypted connection');

        $smtp->helo('localhost');
    }

    #[Test]
    public function sendsNoCredentialsOverUnencryptedConnection(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::None, new Plain('orders', self::AUTH_VALUE), server: $server);
        $smtp->connect();

        try {
            $smtp->helo('localhost');
        } catch (RuntimeException) {
            static::assertSame(['EHLO localhost'], $server->sentLines());
            return;
        }

        static::fail('Credentials were sent unencrypted');
    }

    #[Test]
    public function authenticatesOverUnencryptedConnectionWhenAllowed(): void
    {
        $smtp = new Smtp(
            new ConnectionConfig('mail.example.com', security: Security::None),
            config: ['allow_insecure_auth' => true],
            authenticator: new Plain('orders', self::AUTH_VALUE),
            connection: new SmtpServer(),
        );
        $smtp->connect();
        $smtp->helo('localhost');

        static::assertTrue($smtp->isAuthenticated());
    }

    #[Test]
    public function refusesMechanismServerDoesNotOffer(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::Tls, new CramMd5('orders', self::AUTH_VALUE), server: $server);
        $server->setCapabilities('AUTH PLAIN LOGIN');
        $smtp->connect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server does not offer AUTH CRAM-MD5; it offers "PLAIN LOGIN"');

        $smtp->helo('localhost');
    }

    #[Test]
    public function refusesAuthenticationWhenServerOffersNone(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::Tls, new Plain('orders', self::AUTH_VALUE), server: $server);
        $server->setCapabilities('SIZE 1000');
        $smtp->connect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server does not offer AUTH PLAIN; it offers ""');

        $smtp->helo('localhost');
    }

    #[Test]
    public function reportsRejectedCredentials(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp(Security::Tls, new Plain('orders', self::AUTH_VALUE), server: $server);
        $server->reply(base64_encode("\0orders\0" . self::AUTH_VALUE), '535 5.7.8 Authentication credentials invalid');
        $smtp->connect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('5.7.8 Authentication credentials invalid');
        $this->expectExceptionCode(535);

        $smtp->helo('localhost');
    }

    #[Test]
    public function matchesMechanismNameInAnyCase(): void
    {
        $smtp = self::session(Security::Tls, new class implements AuthenticatorInterface {
            public function mechanism(): string
            {
                return 'plain';
            }

            public function authenticate(ChannelInterface $channel): void
            {
                $channel->exchange('AUTH PLAIN', 334);
                $channel->exchangeSecret(base64_encode("\0orders\0secret"), 235);
            }
        });

        static::assertTrue($smtp->isAuthenticated());
    }

    /**
     * A secret line refused before sending must not leave the log hiding later commands.
     */
    #[Test]
    public function logsCommandsAfterRefusedSecretLine(): void
    {
        $smtp = self::smtp(Security::Tls, new class implements AuthenticatorInterface {
            public function mechanism(): string
            {
                return 'PLAIN';
            }

            public function authenticate(ChannelInterface $channel): void
            {
                $channel->exchange('AUTH PLAIN', 334);
                $channel->exchangeSecret("secret\r\nNOOP", 235);
            }
        });
        $smtp->connect();
        try {
            $smtp->helo('localhost');
        } catch (InvalidArgumentException $e) {
            static::assertSame('An SMTP command must not contain CR, LF or NUL', $e->getMessage());
        }

        $smtp->noop();

        static::assertStringEndsWith("NOOP\r\n250 2.0.0 OK\r\n", $smtp->getLog());
    }

    #[Test]
    public function hidesRefusedSecretLineFromLastRequest(): void
    {
        $smtp = self::smtp(Security::Tls, new class implements AuthenticatorInterface {
            public function mechanism(): string
            {
                return 'PLAIN';
            }

            public function authenticate(ChannelInterface $channel): void
            {
                $channel->exchange('AUTH PLAIN', 334);
                $channel->exchangeSecret("secret\r\nNOOP", 235);
            }
        });
        $smtp->connect();

        try {
            $smtp->helo('localhost');
        } catch (InvalidArgumentException) {
            static::assertSame(Smtp::HIDDEN_LINE, $smtp->getRequest());
            return;
        }

        static::fail('A secret line with a line break was sent');
    }

    #[Test]
    public function refusesSecondAuthentication(): void
    {
        $smtp = self::session(Security::Tls, new Plain('orders', self::AUTH_VALUE));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Already authenticated for this session');

        $smtp->auth();
    }

    #[Test]
    public function authenticatesAgainInNewSession(): void
    {
        $smtp = self::session(Security::Tls, new Plain('orders', self::AUTH_VALUE));
        $smtp->quit();
        $smtp->connect();
        $smtp->helo('localhost');

        static::assertTrue($smtp->isAuthenticated());
    }

    /**
     * @return array<string, array{Security}>
     */
    public static function securityProvider(): array
    {
        return [
            'TLS from the start' => [Security::Tls],
            'STARTTLS'           => [Security::StartTls],
            'none'               => [Security::None],
        ];
    }

    /**
     * @return array<string, array{Security, int}>
     */
    public static function portProvider(): array
    {
        return [
            'TLS from the start' => [Security::Tls, 465],
            'STARTTLS'           => [Security::StartTls, 587],
            'none'               => [Security::None, 25],
        ];
    }

    /**
     * @return array<string, array{Security, bool}>
     */
    public static function encryptionProvider(): array
    {
        return [
            'TLS from the start' => [Security::Tls, true],
            'STARTTLS'           => [Security::StartTls, true],
            'none'               => [Security::None, false],
        ];
    }

    /**
     * @return array<string, array{Security}>
     */
    public static function noStartTlsProvider(): array
    {
        return [
            'TLS from the start' => [Security::Tls],
            'none'               => [Security::None],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function secretProvider(): array
    {
        return [
            'encoded password' => [base64_encode(self::AUTH_VALUE)],
            'encoded username' => [base64_encode('orders')],
        ];
    }

    private static function smtp(
        Security $security,
        ?AuthenticatorInterface $auth = null,
        ?SmtpServer $server = null,
    ): Smtp {
        return new Smtp(
            new ConnectionConfig('mail.example.com', security: $security),
            authenticator: $auth,
            connection: $server ?? new SmtpServer(),
        );
    }

    private static function session(
        Security $security,
        ?AuthenticatorInterface $auth = null,
        ?SmtpServer $server = null,
    ): Smtp {
        $smtp = self::smtp($security, $auth, $server);
        $smtp->connect();
        $smtp->helo('localhost');

        return $smtp;
    }
}
