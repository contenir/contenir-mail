<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use ArrayIterator;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Smtp\Auth\Plain;
use Contenir\Mail\Protocol\Smtp\Auth\ScramSha256;
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
use Contenir\Mail\Storage\ImapConfig;
use Contenir\Mail\Storage\Pop3Config;
use Contenir\Mail\Storage\RemoteAuth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function print_r;

/**
 * The "auth" setting of ImapConfig and Pop3Config: XOAUTH2 or SCRAM-SHA-256.
 */
#[CoversClass(RemoteAuth::class)]
#[CoversClass(ImapConfig::class)]
#[CoversClass(Pop3Config::class)]
#[Group('unit')]
final class RemoteAuthTest extends TestCase
{
    /**
     * @mago-expect lint:no-literal-password A made-up value the tests look for, not a credential.
     */
    private const string PASSWORD = 'hunter2-secret';

    /**
     * @param class-string<ImapConfig|Pop3Config> $class
     * @param array<string, string> $auth
     * @param class-string $expected
     */
    #[Test]
    #[DataProvider('settingsProvider')]
    public function readsTheAuthenticatorFromSettings(string $class, array $auth, string $expected): void
    {
        $config = $class::fromIterable(['auth' => $auth]);

        static::assertSame(
            [$expected, 'jo@example.com'],
            [null === $config->auth ? null : $config->auth::class, $config->user],
        );
    }

    /**
     * @return array<string, array{class-string<ImapConfig|Pop3Config>, array<string, string>, class-string}>
     */
    public static function settingsProvider(): array
    {
        $scram = ['username' => 'jo@example.com', 'password' => self::PASSWORD];
        $token = ['username' => 'jo@example.com', 'access_token' => self::PASSWORD];
        $cases = [];
        foreach ([ImapConfig::class, Pop3Config::class] as $class) {
            $cases += [
                "{$class}, scram-sha-256"       => [$class, ['type' => 'scram-sha-256', ...$scram], ScramSha256::class],
                "{$class}, SCRAM_SHA_256"       => [$class, ['type' => 'SCRAM_SHA_256', ...$scram], ScramSha256::class],
                "{$class}, xoauth2"             => [$class, ['type' => 'xoauth2', ...$token], XOAuth2::class],
                "{$class}, XOAUTH2"             => [$class, ['type' => 'XOAUTH2', ...$token], XOAuth2::class],
                "{$class}, no type, as XOAUTH2" => [$class, $token, XOAuth2::class],
            ];
        }

        return $cases;
    }

    #[Test]
    public function readsSettingsFromAnyIterable(): void
    {
        $auth = RemoteAuth::fromIterable(new ArrayIterator([
            'type'     => 'scram-sha-256',
            'username' => 'jo@example.com',
            'password' => self::PASSWORD,
        ]));

        static::assertSame(['jo@example.com', ScramSha256::class], [$auth->username, $auth::class]);
    }

    /**
     * @param class-string<ImapConfig|Pop3Config> $class
     */
    #[Test]
    #[DataProvider('configProvider')]
    public function takesAScramAuthenticatorAsItIs(string $class): void
    {
        $auth = new ScramSha256('jo@example.com', self::PASSWORD);

        static::assertSame($auth, $class::fromIterable(['auth' => $auth])->auth);
    }

    /**
     * @param class-string<ImapConfig|Pop3Config> $class
     */
    #[Test]
    #[DataProvider('configProvider')]
    public function refusesAnAuthenticatorTheMailboxCannotUse(string $class): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "{$class}: option \"auth\" must be an XOAuth2 or ScramSha256 authenticator, got " . Plain::class,
        );

        $class::fromIterable(['auth' => new Plain('jo', self::PASSWORD)]);
    }

    /**
     * @param class-string<ImapConfig|Pop3Config> $class
     */
    #[Test]
    #[DataProvider('configProvider')]
    public function refusesAnUnknownType(string $class): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Mailbox authentication: unknown type "plain"; expected xoauth2 or scram-sha-256',
        );

        $class::fromIterable(['auth' => ['type' => 'plain', 'username' => 'jo', 'password' => self::PASSWORD]]);
    }

    /**
     * @param class-string<ImapConfig|Pop3Config> $class
     */
    #[Test]
    #[DataProvider('configProvider')]
    public function refusesATypeThatIsNotAString(string $class): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailbox authentication: option "type" must be a string, got int');

        $class::fromIterable(['auth' => ['type' => 1, 'username' => 'jo', 'password' => self::PASSWORD]]);
    }

    /**
     * @param class-string<ImapConfig|Pop3Config> $class
     */
    #[Test]
    #[DataProvider('configProvider')]
    public function masksThePasswordInDumps(string $class): void
    {
        $config = $class::fromIterable([
            'auth' => ['type' => 'scram-sha-256', 'username' => 'jo@example.com', 'password' => self::PASSWORD],
        ]);

        static::assertStringNotContainsString(self::PASSWORD, print_r($config, return: true));
    }

    /**
     * @return array<string, array{class-string<ImapConfig|Pop3Config>}>
     */
    public static function configProvider(): array
    {
        return [
            'IMAP' => [ImapConfig::class],
            'POP3' => [Pop3Config::class],
        ];
    }
}
