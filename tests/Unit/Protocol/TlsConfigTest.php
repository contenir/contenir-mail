<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Smtp;
use Contenir\Mail\Protocol\TlsConfig;
use Contenir\Mail\Storage\ImapConfig;
use Contenir\Mail\Storage\Pop3Config;
use Contenir\Mail\Storage\RemoteConnection;
use Contenir\Mail\Transport\SmtpConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function print_r;

/**
 * TLS settings beyond peer verification (contenir/contenir-mail#16).
 */
#[CoversClass(TlsConfig::class)]
#[CoversClass(ConnectionConfig::class)]
#[CoversClass(SmtpConfig::class)]
#[CoversClass(RemoteConnection::class)]
#[Group('unit')]
final class TlsConfigTest extends TestCase
{
    private const array SETTINGS = [
        'cafile'            => '/etc/ssl/internal-ca.pem',
        'capath'            => '/etc/ssl/certs',
        'peer_name'         => 'mail.internal',
        'allow_self_signed' => true,
        'local_cert'        => '/etc/ssl/client.pem',
        'local_pk'          => '/etc/ssl/client.key',
        'passphrase'        => 'unlocks the key',
    ];

    #[Test]
    public function addsNoContextOptionsByDefault(): void
    {
        static::assertSame([], (new TlsConfig())->contextOptions());
    }

    #[Test]
    public function givesEverySettingAsItsContextOption(): void
    {
        static::assertEquals(self::SETTINGS, ConnectionConfig::fromIterable(self::SETTINGS)->tls->contextOptions());
    }

    #[Test]
    public function redactsThePassphraseInDumps(): void
    {
        $tls = new TlsConfig(
            caFile: '/etc/ssl/internal-ca.pem',
            caPath: '/etc/ssl/certs',
            peerName: 'mail.internal',
            allowSelfSigned: true,
            localCert: '/etc/ssl/client.pem',
            localPrivateKey: '/etc/ssl/client.key',
            localPrivateKeyPassphrase: 'unlocks the key',
        );

        static::assertSame(
            [
                'caFile'                    => '/etc/ssl/internal-ca.pem',
                'caPath'                    => '/etc/ssl/certs',
                'peerName'                  => 'mail.internal',
                'allowSelfSigned'           => true,
                'localCert'                 => '/etc/ssl/client.pem',
                'localPrivateKey'           => '/etc/ssl/client.key',
                'localPrivateKeyPassphrase' => TlsConfig::REDACTED,
            ],
            $tls->__debugInfo(),
        );
    }

    #[Test]
    public function keepsThePassphraseOutOfANestedDump(): void
    {
        $config = new ConnectionConfig(tls: new TlsConfig(
            localCert: 'c.pem',
            localPrivateKeyPassphrase: 'unlocks',
        ));

        static::assertStringNotContainsString('unlocks', print_r($config, return: true));
    }

    #[Test]
    public function showsNoPassphraseWhenNoneIsSet(): void
    {
        static::assertNull((new TlsConfig())->__debugInfo()['localPrivateKeyPassphrase']);
    }

    #[Test]
    public function leavesSelfSignedCertificatesRefusedUnlessAllowed(): void
    {
        static::assertSame(
            ['cafile' => '/etc/ssl/internal-ca.pem'],
            (new TlsConfig(
                caFile: '/etc/ssl/internal-ca.pem',
                allowSelfSigned: false,
            ))->contextOptions(),
        );
    }

    /**
     * @param class-string<SmtpConfig|ImapConfig|Pop3Config> $class
     */
    #[Test]
    #[DataProvider('configProvider')]
    public function readsTheSettingsInEveryConfig(string $class, array $settings): void
    {
        $config = $class::fromIterable([...$settings, ...self::SETTINGS]);

        static::assertEquals(self::SETTINGS, $config->connection->tls->contextOptions());
    }

    /**
     * @return array<string, array{class-string, array<string, string>}>
     */
    public static function configProvider(): array
    {
        return [
            'SMTP transport' => [SmtpConfig::class, []],
            'IMAP storage'   => [ImapConfig::class, ['user' => 'jo']],
            'POP3 storage'   => [Pop3Config::class, ['user' => 'jo']],
        ];
    }

    #[Test]
    public function readsTheSettingsForTheSmtpProtocol(): void
    {
        $smtp = new Smtp([
            'host'           => 'mail.internal',
            'cafile'         => '/etc/ssl/internal-ca.pem',
            'novalidatecert' => false,
        ]);

        static::assertSame(
            ['cafile' => '/etc/ssl/internal-ca.pem'],
            $smtp->getConnectionConfig()->tls->contextOptions(),
        );
    }

    #[Test]
    #[DataProvider('invalidSettingProvider')]
    public function refusesAnInvalidSetting(array $settings, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ConnectionConfig::fromIterable($settings);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidSettingProvider(): array
    {
        $controls = 'must not be empty or contain control characters';

        return [
            'empty cafile'                     => [['cafile' => ''], "TLS setting \"cafile\" {$controls}"],
            'NUL in capath'                    => [['capath' => "/etc\0/ssl"], "TLS setting \"capath\" {$controls}"],
            'line feed in peer name'           => [
                ['peer_name' => "mail\n.internal"],
                "TLS setting \"peer_name\" {$controls}",
            ],
            'DEL in certificate'               => [
                ['local_cert' => "a\x7Fb"],
                "TLS setting \"local_cert\" {$controls}",
            ],
            'empty key'                        => [
                ['local_cert' => 'c.pem', 'local_pk' => ''],
                "TLS setting \"local_pk\" {$controls}",
            ],
            'key without its certificate'      => [
                ['local_pk' => 'c.key'],
                'A TLS private key (local_pk) needs its certificate (local_cert)',
            ],
            'passphrase without a certificate' => [
                ['passphrase' => 'unlocks the key'],
                'A TLS passphrase needs the certificate it unlocks (local_cert)',
            ],
            'misspelt setting'                 => [['ca_file' => 'x'], 'ca_file'],
        ];
    }
}
