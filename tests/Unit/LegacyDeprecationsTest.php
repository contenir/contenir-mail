<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Closure;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\LegacyOptions;
use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Protocol\ProtocolTrait;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp;
use Contenir\Mail\Storage\ImapConfig;
use Contenir\Mail\Storage\Pop3Config;
use Contenir\Mail\Storage\RemoteConnection;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use Contenir\Mail\Transport\SmtpConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The laminas-mail forms kept for compatibility still work, and PHP 8.4 and later
 * report their use through #[\Deprecated]. The forms that replace them report nothing.
 */
#[CoversClass(Imap::class)]
#[CoversClass(LegacyOptions::class)]
#[CoversClass(Pop3::class)]
#[CoversClass(RemoteConnection::class)]
#[CoversClass(Smtp::class)]
#[CoversClass(SmtpConfig::class)]
#[CoversTrait(ProtocolTrait::class)]
#[Group('unit')]
final class LegacyDeprecationsTest extends TestCase
{
    private const string SINCE = ' is deprecated since 0.3.0, ';

    /**
     * @param Closure(): mixed $legacy
     */
    #[DataProvider('legacyProvider')]
    #[IgnoreDeprecations]
    #[RequiresPhp('>= 8.4')]
    #[Test]
    public function reportsTheLaminasForm(Closure $legacy, string $message): void
    {
        $this->expectUserDeprecationMessage($message);

        $legacy();
    }

    /**
     * @return array<string, array{Closure(): mixed, string}>
     */
    public static function legacyProvider(): array
    {
        $legacyOptions =
            'Method Contenir\Mail\Protocol\LegacyOptions::config()'
            . self::SINCE
            . 'pass a ConnectionConfig to Protocol\Imap or Protocol\Pop3 instead of a host, port and "ssl"';
        $ssl =
            'Method Contenir\Mail\Storage\RemoteConnection::legacySecurity()'
            . self::SINCE
            . 'use "security" instead of the laminas-mail "ssl" setting';
        $noValidate =
            'Method Contenir\Mail\Storage\RemoteConnection::legacyVerifyPeer()'
            . self::SINCE
            . 'use "verify_peer" instead of the laminas-mail "novalidatecert" setting';
        $smtp =
            'Method Contenir\Mail\Protocol\Smtp::readLegacySettings()'
            . self::SINCE
            . 'pass a ConnectionConfig to Protocol\Smtp instead of a host name or settings array';

        return [
            'IMAP host, port and ssl'      => [
                static fn(): Imap => new Imap(
                    'imap.example.com',
                    null,
                    false,
                    false,
                    ScriptedServer::imapGreeting()->hangUp(),
                ),
                $legacyOptions,
            ],
            'POP3 connect() with a host'   => [
                static fn(): string => (new Pop3(connection: ScriptedServer::pop3Greeting()->hangUp()))->connect(
                    'pop.example.com',
                    ssl: false,
                ),
                $legacyOptions,
            ],
            'setNoValidateCert()'          => [
                static fn(): Imap => (new Imap(connection: new InMemoryConnection()))->setNoValidateCert(true),
                'Method Contenir\Mail\Protocol\Imap::setNoValidateCert()'
                    . self::SINCE
                    . 'set verifyPeer in the ConnectionConfig instead',
            ],
            'IMAP storage ssl'             => [
                static fn(): ImapConfig => ImapConfig::fromIterable(['user' => 'jo', 'ssl' => 'ssl']),
                $ssl,
            ],
            'POP3 storage novalidatecert'  => [
                static fn(): Pop3Config => Pop3Config::fromIterable(['user' => 'jo', 'novalidatecert' => true]),
                $noValidate,
            ],
            'SMTP transport ssl'           => [
                static fn(): SmtpConfig => SmtpConfig::fromIterable(['ssl' => 'tls']),
                $ssl,
            ],
            'SMTP protocol host name'      => [
                static fn(): Smtp => new Smtp('mail.example.com'),
                $smtp,
            ],
            'SMTP protocol settings array' => [
                static fn(): Smtp => new Smtp(['host' => 'mail.example.com', 'security' => 'tls']),
                $smtp,
            ],
        ];
    }

    /**
     * Run with failOnDeprecation, so a deprecation here fails the test on PHP 8.4 and later.
     *
     * @param Closure(): mixed $current
     */
    #[DataProvider('currentProvider')]
    #[Test]
    public function reportsNothingForTheFormsThatReplaceThem(Closure $current): void
    {
        static::assertIsObject($current());
    }

    /**
     * @return array<string, array{Closure(): mixed}>
     */
    public static function currentProvider(): array
    {
        $plain = new ConnectionConfig('mail.example.com', security: Security::None);

        return [
            'IMAP ConnectionConfig'            => [
                static fn(): Imap => new Imap($plain, connection: ScriptedServer::imapGreeting()->hangUp()),
            ],
            'POP3 ConnectionConfig'            => [
                static fn(): Pop3 => new Pop3($plain, connection: ScriptedServer::pop3Greeting()->hangUp()),
            ],
            'storage security and verify_peer' => [
                static fn(): ImapConfig => ImapConfig::fromIterable([
                    'user'        => 'jo',
                    'security'    => 'tls',
                    'verify_peer' => false,
                ]),
            ],
            'SMTP transport security'          => [
                static fn(): SmtpConfig => SmtpConfig::fromIterable(['security' => 'tls']),
            ],
            'SMTP protocol ConnectionConfig'   => [
                static fn(): Smtp => new Smtp($plain, config: ['use_complete_quit' => false]),
            ],
            'SMTP protocol without arguments'  => [
                static fn(): Smtp => new Smtp(),
            ],
        ];
    }

    #[Test]
    public function connectsAsBeforeWithoutArguments(): void
    {
        static::assertEquals(new ConnectionConfig(), (new Smtp())->getConnectionConfig());
    }
}
