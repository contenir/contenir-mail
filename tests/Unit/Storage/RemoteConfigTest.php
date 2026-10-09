<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use ArrayIterator;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Sasl\Xoauth2;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Storage\ImapConfig;
use Contenir\Mail\Storage\LocalPath;
use Contenir\Mail\Storage\Pop3Config;
use Contenir\Mail\Storage\RemoteAuth;
use Contenir\Mail\Storage\RemoteConnection;
use Contenir\Mail\Storage\RemoteFolder;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\FakeMechanism;
use Contenir\Mail\Tests\Unit\TestAsset\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function print_r;

#[CoversClass(ImapConfig::class)]
#[CoversClass(Pop3Config::class)]
#[CoversClass(RemoteConnection::class)]
#[CoversClass(RemoteAuth::class)]
#[CoversClass(RemoteFolder::class)]
#[CoversClass(LocalPath::class)]
#[Group('unit')]
final class RemoteConfigTest extends TestCase
{
    /**
     * Not a real password: a value to look for where none should be.
     *
     * @mago-expect lint:no-literal-password A made-up value the tests look for, not a credential.
     */
    private const string PASSWORD = 'hunter2-secret';

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('securityProvider')]
    #[Test]
    public function readsSecurity(array $settings, Security $expected): void
    {
        static::assertSame($expected, ImapConfig::fromIterable(['user' => 'u', ...$settings])->connection->security);
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('securityProvider')]
    #[Test]
    public function readsPop3SecurityAlike(array $settings, Security $expected): void
    {
        static::assertSame($expected, Pop3Config::fromIterable(['user' => 'u', ...$settings])->connection->security);
    }

    #[DataProvider('unknownSslProvider')]
    #[Test]
    public function refusesUnknownSslValue(mixed $ssl, string $shown): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Contenir\\Mail\\Storage\\ImapConfig: option \"ssl\" must be \"ssl\", \"tls\", \"starttls\", \"none\" or false, got \"{$shown}\"",
        );

        ImapConfig::fromIterable(['user' => 'u', 'ssl' => $ssl]);
    }

    #[Test]
    public function refusesSslOfTheWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('option "ssl" must be a string or a bool, got array');

        ImapConfig::fromIterable(['user' => 'u', 'ssl' => []]);
    }

    #[DataProvider('conflictProvider')]
    #[Test]
    public function refusesSettingGivenUnderBothNames(array $settings, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Pop3Config::fromIterable(['user' => 'u', ...$settings]);
    }

    #[DataProvider('verifyPeerProvider')]
    #[Test]
    public function readsPeerVerification(array $settings, bool $expected): void
    {
        static::assertSame($expected, ImapConfig::fromIterable(['user' => 'u', ...$settings])->connection->verifyPeer);
    }

    #[Test]
    public function readsConnectionSettings(): void
    {
        $connection = ImapConfig::fromIterable([
            'host'    => 'imap.example.com',
            'port'    => '143',
            'timeout' => 5,
            'user'    => 'u',
        ])->connection;

        static::assertSame(['imap.example.com', 143, 5], [$connection->host, $connection->port, $connection->timeout]);
    }

    #[Test]
    public function readsLogin(): void
    {
        $config = ImapConfig::fromIterable(new ArrayIterator([
            'user'     => 'u',
            'password' => self::PASSWORD,
            'folder'   => 'Archive',
        ]));

        static::assertSame(['u', self::PASSWORD, 'Archive'], [$config->user, $config->password, $config->folder]);
    }

    #[Test]
    public function readsPop3Login(): void
    {
        $config = Pop3Config::fromIterable(['user' => 'u', 'password' => self::PASSWORD]);

        static::assertSame(['u', self::PASSWORD], [$config->user, $config->password]);
    }

    #[Test]
    public function readsDefaultLogin(): void
    {
        $config = ImapConfig::fromIterable(['user' => 'u']);

        static::assertSame(['', 'INBOX'], [$config->password, $config->folder]);
    }

    #[DataProvider('configProvider')]
    #[Test]
    public function readsAnAccessTokenInPlaceOfTheUser(string $class): void
    {
        $config = $class::fromIterable(['auth' => ['username' => 'jo@example.com', 'access_token' => self::PASSWORD]]);

        static::assertSame(['jo@example.com', 'jo@example.com'], [$config->user, $config->auth?->username]);
    }

    /**
     * @param class-string<ImapConfig|Pop3Config> $class
     */
    #[DataProvider('configProvider')]
    #[Test]
    public function readsTheLogger(string $class): void
    {
        $logger = new RecordingLogger();

        static::assertSame($logger, $class::fromIterable(['user' => 'u', 'logger' => $logger])->connection->logger);
    }

    /**
     * A mechanism other than the built-in ones has no username to sign in as.
     *
     * @param class-string<ImapConfig|Pop3Config> $class
     */
    #[DataProvider('configProvider')]
    #[Test]
    public function readsNoUserFromAnotherMechanism(string $class): void
    {
        static::assertSame('', $class::fromIterable(['auth' => new FakeMechanism()])->user);
    }

    #[DataProvider('configProvider')]
    #[Test]
    public function keepsTheUserGivenWithAnAccessToken(string $class): void
    {
        $config = $class::fromIterable([
            'user' => 'shared@example.com',
            'auth' => new Xoauth2('jo@example.com', self::PASSWORD),
        ]);

        static::assertSame('shared@example.com', $config->user);
    }

    #[DataProvider('configProvider')]
    #[Test]
    public function takesAnAuthenticatorAsItIs(string $class): void
    {
        $auth = new Xoauth2('jo@example.com', static fn(): string => self::PASSWORD);

        static::assertSame($auth, $class::fromIterable(['auth' => $auth])->auth);
    }

    #[DataProvider('configProvider')]
    #[Test]
    public function refusesAnAccessTokenGivenAsAString(string $class): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("{$class}: option \"auth\"");

        $class::fromIterable(['auth' => self::PASSWORD]);
    }

    #[DataProvider('configProvider')]
    #[Test]
    public function masksTheAccessTokenInDumps(string $class): void
    {
        $config = $class::fromIterable(['auth' => ['username' => 'jo@example.com', 'access_token' => self::PASSWORD]]);

        static::assertStringNotContainsString(self::PASSWORD, print_r($config, return: true));
    }

    #[DataProvider('configProvider')]
    #[Test]
    public function requiresUser(string $class): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("{$class}: option \"user\" is required");

        $class::fromIterable(['host' => 'x']);
    }

    #[Test]
    public function refusesFolderOptionForPop3(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown option "folder"');

        Pop3Config::fromIterable(['user' => 'u', 'folder' => 'INBOX']);
    }

    /**
     * Command injection: a folder name with a line break is refused in the settings already.
     */
    #[Test]
    public function refusesFolderNameWithLineBreak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A folder name may not be empty or hold a line break or NUL');

        new ImapConfig(new ConnectionConfig(), 'u', folder: "INBOX\r\nA1 DELETE x");
    }

    /**
     * Credential leaks: dumping a config shows a mask, never the password.
     */
    #[DataProvider('dumpProvider')]
    #[Test]
    public function masksPasswordInDumps(object $config): void
    {
        static::assertStringNotContainsString(self::PASSWORD, print_r($config, return: true));
    }

    #[Test]
    public function keepsPasswordInTheObject(): void
    {
        static::assertSame(
            self::PASSWORD,
            Pop3Config::fromIterable(['user' => 'u', 'password' => self::PASSWORD])->password,
        );
    }

    #[Test]
    public function showsTheRestInDumps(): void
    {
        $config = ImapConfig::fromIterable(['user' => 'someone', 'folder' => 'Archive', 'password' => self::PASSWORD]);

        static::assertSame(['someone', '********', 'Archive'], [
            $config->__debugInfo()['user'],
            $config->__debugInfo()['password'],
            $config->__debugInfo()['folder'],
        ]);
    }

    #[Test]
    public function showsThePop3RestInDumps(): void
    {
        $config = Pop3Config::fromIterable(['user' => 'someone', 'password' => self::PASSWORD]);

        static::assertSame(['someone', '********'], [
            $config->__debugInfo()['user'],
            $config->__debugInfo()['password'],
        ]);
    }

    #[Test]
    public function showsTheImapConnectionInDumps(): void
    {
        $config = ImapConfig::fromIterable(['user' => 'someone', 'host' => 'imap.example.com']);

        static::assertSame($config->connection, $config->__debugInfo()['connection']);
    }

    #[Test]
    public function showsTheConnectionInDumps(): void
    {
        $config = Pop3Config::fromIterable(['user' => 'someone', 'host' => 'pop.example.com']);

        static::assertSame($config->connection, $config->__debugInfo()['connection']);
    }

    #[Test]
    public function allowsEmptyRootFolder(): void
    {
        static::assertSame('', RemoteFolder::checkOptional(''));
    }

    #[Test]
    public function checksNonEmptyRootFolder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A folder name may not be empty or hold a line break or NUL');

        RemoteFolder::checkOptional("a\0");
    }

    /**
     * @return array<string, array{array<string, mixed>, Security}>
     */
    public static function securityProvider(): array
    {
        return [
            'omitted'        => [[], Security::StartTls],
            'null ssl'       => [['ssl' => null], Security::StartTls],
            'security tls'   => [['security' => 'tls'], Security::Tls],
            'security none'  => [['security' => Security::None], Security::None],
            'security start' => [['security' => 'starttls'], Security::StartTls],
            'ssl SSL'        => [['ssl' => 'SSL'], Security::Tls],
            'ssl TLS'        => [['ssl' => 'TLS'], Security::StartTls],
            'ssl starttls'   => [['ssl' => 'starttls'], Security::StartTls],
            'ssl none'       => [['ssl' => 'none'], Security::None],
            'ssl empty'      => [['ssl' => ''], Security::None],
            'ssl false'      => [['ssl' => false], Security::None],
            'ssl zero'       => [['ssl' => 0], Security::None],
        ];
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function unknownSslProvider(): array
    {
        return [
            'true'    => [true, 'true'],
            'one'     => [1, 'true'],
            'unknown' => ['sslv3', 'sslv3'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function conflictProvider(): array
    {
        return [
            'security' => [
                ['security' => 'tls', 'ssl' => 'ssl'],
                'Contenir\Mail\Storage\Pop3Config: give option "security" or its laminas-mail form "ssl", not both',
            ],
            'peer'     => [
                ['verify_peer' => true, 'novalidatecert' => true],
                'Contenir\Mail\Storage\Pop3Config: give option "verify_peer" or its laminas-mail form "novalidatecert", not both',
            ],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function verifyPeerProvider(): array
    {
        return [
            'default'              => [[], true],
            'verify peer off'      => [['verify_peer' => false], false],
            'novalidatecert true'  => [['novalidatecert' => true], false],
            'novalidatecert false' => [['novalidatecert' => 'false'], true],
        ];
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function configProvider(): array
    {
        return [
            'imap' => [ImapConfig::class],
            'pop3' => [Pop3Config::class],
        ];
    }

    /**
     * @return array<string, array{object}>
     */
    public static function dumpProvider(): array
    {
        return [
            'imap' => [new ImapConfig(new ConnectionConfig(), 'u', self::PASSWORD)],
            'pop3' => [new Pop3Config(new ConnectionConfig(), 'u', self::PASSWORD)],
        ];
    }
}
