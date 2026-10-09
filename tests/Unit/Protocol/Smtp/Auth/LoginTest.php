<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp\Auth;

use Contenir\Mail\Exception\InvalidArgumentException as MailInvalidArgumentException;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Smtp\Auth\Credentials;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Tests\TestAsset\ScriptedChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

use function base64_encode;
use function ob_get_clean;
use function ob_start;
use function var_dump;

/**
 * @mago-expect lint:no-debug-symbols var_dump() and print_r() are what these tests prove keep secrets hidden.
 */
#[CoversClass(Login::class)]
#[CoversClass(Credentials::class)]
#[Group('unit')]
final class LoginTest extends TestCase
{
    private const string AUTH_VALUE = 'correct horse battery staple';

    #[Test]
    public function sendsUsernameThenPasswordAsSecretResponses(): void
    {
        $channel = new ScriptedChannel();

        (new Login('orders', self::AUTH_VALUE))->authenticate($channel);

        static::assertSame(
            [
                ['line' => 'AUTH LOGIN', 'expect' => 334, 'secret' => false],
                ['line' => base64_encode('orders'), 'expect' => 334, 'secret' => true],
                ['line' => base64_encode(self::AUTH_VALUE), 'expect' => 235, 'secret' => true],
            ],
            $channel->steps(),
        );
    }

    #[Test]
    public function namesLoginMechanism(): void
    {
        static::assertSame('LOGIN', (new Login('orders', self::AUTH_VALUE))->mechanism());
    }

    #[Test]
    public function readsCredentialsFromSettings(): void
    {
        $channel = new ScriptedChannel();

        Login::fromIterable(['username' => 'orders', 'password' => self::AUTH_VALUE])->authenticate($channel);

        static::assertSame(base64_encode(self::AUTH_VALUE), $channel->steps()[2]['line']);
    }

    #[Test]
    public function rejectsUnknownSetting(): void
    {
        $this->expectException(MailInvalidArgumentException::class);
        $this->expectExceptionMessage('unknown option "token"');

        Login::fromIterable(['username' => 'orders', 'token' => self::AUTH_VALUE]);
    }

    #[DataProvider('invalidCredentialsProvider')]
    #[Test]
    public function rejectsInvalidCredentials(
        string $username,
        #[SensitiveParameter]
        string $password,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Login($username, $password);
    }

    #[Test]
    public function keepsPasswordOutOfDumps(): void
    {
        ob_start();
        var_dump(new Login('orders', self::AUTH_VALUE));

        static::assertStringNotContainsString(self::AUTH_VALUE, (string) ob_get_clean());
    }

    #[Test]
    public function showsUsernameInDumps(): void
    {
        static::assertSame(
            ['username' => 'orders', 'password' => Credentials::HIDDEN],
            (new Login('orders', self::AUTH_VALUE))->__debugInfo(),
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidCredentialsProvider(): array
    {
        return [
            'empty username'   => ['', self::AUTH_VALUE, 'LOGIN authentication requires a username'],
            'CRLF in username' => [
                "orders\r\nQUIT",
                self::AUTH_VALUE,
                'The LOGIN username must not contain control characters',
            ],
            'empty password'   => ['orders', '', 'LOGIN authentication requires a password'],
        ];
    }
}
