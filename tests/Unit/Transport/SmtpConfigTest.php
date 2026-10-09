<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use ArrayIterator;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Tests\Unit\TestAsset\RecordingLogger;
use Contenir\Mail\Transport\SmtpConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function ob_get_clean;
use function ob_start;
use function var_dump;

/**
 * @mago-expect lint:no-debug-symbols var_dump() and print_r() are what these tests prove keep secrets hidden.
 */
#[CoversClass(SmtpConfig::class)]
#[Group('unit')]
final class SmtpConfigTest extends TestCase
{
    private const string AUTH_VALUE = 'correct horse battery staple';

    #[Test]
    public function requiresStartTlsByDefault(): void
    {
        static::assertSame(Security::StartTls, (new SmtpConfig())->connection->security);
    }

    #[Test]
    public function requiresStartTlsByDefaultFromSettings(): void
    {
        static::assertSame(Security::StartTls, SmtpConfig::fromIterable([])->connection->security);
    }

    #[Test]
    public function connectsWithConnectionConfigDefaults(): void
    {
        static::assertEquals(new ConnectionConfig(), (new SmtpConfig())->connection);
    }

    #[Test]
    public function readsSameDefaultsFromEmptySettings(): void
    {
        static::assertEquals(new SmtpConfig(), SmtpConfig::fromIterable([]));
    }

    #[Test]
    public function hasLaminasDefaults(): void
    {
        $config = new SmtpConfig();

        static::assertSame(
            ['localhost', null, false, null, true],
            [
                $config->name,
                $config->auth,
                $config->allowInsecureAuth,
                $config->connectionTimeLimit,
                $config->useCompleteQuit,
            ],
        );
    }

    #[Test]
    public function buildsConnectionConfig(): void
    {
        $config = new SmtpConfig('mail.example.com', 587, Security::Tls, false, 10);

        static::assertEquals(
            new ConnectionConfig('mail.example.com', 587, Security::Tls, false, 10),
            $config->connection,
        );
    }

    #[Test]
    public function readsEverySetting(): void
    {
        $login  = new Login('orders', self::AUTH_VALUE);
        $config = SmtpConfig::fromIterable(new ArrayIterator([
            'host'                  => 'mail.example.com',
            'port'                  => '587',
            'security'              => 'tls',
            'verify_peer'           => 'false',
            'timeout'               => '10',
            'name'                  => 'client.example.com',
            'auth'                  => $login,
            'allow_insecure_auth'   => 'yes',
            'connection_time_limit' => '60',
            'use_complete_quit'     => 'no',
        ]));

        static::assertEquals(
            new SmtpConfig(
                'mail.example.com',
                587,
                Security::Tls,
                false,
                10,
                'client.example.com',
                $login,
                true,
                60,
                false,
            ),
            $config,
        );
    }

    #[Test]
    public function readsTheLogger(): void
    {
        $logger = new RecordingLogger();

        static::assertSame(
            [$logger, $logger],
            [
                SmtpConfig::fromIterable(['logger' => $logger])->connection->logger,
                (new SmtpConfig(logger: $logger))->connection->logger,
            ],
        );
    }

    #[Test]
    public function buildsAuthenticatorFromSettings(): void
    {
        $config = SmtpConfig::fromIterable([
            'auth' => ['type' => 'login', 'username' => 'orders', 'password' => self::AUTH_VALUE],
        ]);

        static::assertEquals(new Login('orders', self::AUTH_VALUE), $config->auth);
    }

    #[Test]
    public function rejectsUnknownSetting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown option "connection_class"');

        SmtpConfig::fromIterable(['connection_class' => 'login']);
    }

    #[Test]
    public function rejectsInvalidClientName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'SMTP client name "bad name" is invalid: The input does not match the expected structure for a DNS hostname',
        );

        new SmtpConfig(name: 'bad name');
    }

    #[DataProvider('timeLimitProvider')]
    #[Test]
    public function rejectsTimeLimitBelowOneSecond(int $seconds): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Connection time limit {$seconds} must be at least one second");

        new SmtpConfig(connectionTimeLimit: $seconds);
    }

    #[Test]
    public function acceptsTimeLimitOfOneSecond(): void
    {
        static::assertSame(1, (new SmtpConfig(connectionTimeLimit: 1))->connectionTimeLimit);
    }

    /**
     * Credentials over a plain connection are readable by anyone on the path.
     */
    #[Test]
    public function refusesAuthenticationOverPlainConnection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'SMTP authentication over an unencrypted connection would expose the credentials; '
                . 'use security "starttls" or "tls", or set allow_insecure_auth',
        );

        new SmtpConfig(
            security: Security::None,
            auth: new Login('orders', self::AUTH_VALUE),
        );
    }

    #[Test]
    public function allowsAuthenticationOverPlainConnectionWhenAsked(): void
    {
        $config = new SmtpConfig(
            security: Security::None,
            auth: new Login('orders', self::AUTH_VALUE),
            allowInsecureAuth: true,
        );

        static::assertTrue($config->allowInsecureAuth);
    }

    #[Test]
    public function allowsPlainConnectionWithoutAuthentication(): void
    {
        static::assertSame(Security::None, (new SmtpConfig(security: Security::None))->connection->security);
    }

    #[Test]
    public function keepsPasswordOutOfDumps(): void
    {
        ob_start();
        var_dump(new SmtpConfig(auth: new Login('orders', self::AUTH_VALUE)));

        static::assertStringNotContainsString(self::AUTH_VALUE, (string) ob_get_clean());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function timeLimitProvider(): array
    {
        return [
            'zero'     => [0],
            'negative' => [-5],
        ];
    }
}
