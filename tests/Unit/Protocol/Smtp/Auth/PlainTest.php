<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp\Auth;

use Contenir\Mail\Exception\InvalidArgumentException as MailInvalidArgumentException;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Smtp\Auth\Credentials;
use Contenir\Mail\Protocol\Smtp\Auth\Plain;
use Contenir\Mail\Tests\Unit\TestAsset\ScriptedChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

use function base64_encode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function var_dump;

/**
 * @mago-expect lint:no-debug-symbols var_dump() and print_r() are what these tests prove keep secrets hidden.
 */
#[CoversClass(Plain::class)]
#[CoversClass(Credentials::class)]
#[Group('unit')]
final class PlainTest extends TestCase
{
    private const string AUTH_VALUE = 'correct horse battery staple';

    #[Test]
    public function sendsUsernameAndPasswordInOneSecretResponse(): void
    {
        $channel = new ScriptedChannel();

        (new Plain('orders', self::AUTH_VALUE))->authenticate($channel);

        static::assertSame(
            [
                ['line' => 'AUTH PLAIN', 'expect' => 334, 'secret' => false],
                ['line' => base64_encode("\0orders\0" . self::AUTH_VALUE), 'expect' => 235, 'secret' => true],
            ],
            $channel->steps(),
        );
    }

    #[Test]
    public function namesPlainMechanism(): void
    {
        static::assertSame('PLAIN', (new Plain('orders', self::AUTH_VALUE))->mechanism());
    }

    #[Test]
    public function readsCredentialsFromSettings(): void
    {
        $channel = new ScriptedChannel();

        Plain::fromIterable(['username' => 'orders', 'password' => self::AUTH_VALUE])->authenticate($channel);

        static::assertSame(base64_encode("\0orders\0" . self::AUTH_VALUE), $channel->steps()[1]['line']);
    }

    #[Test]
    public function rejectsUnknownSetting(): void
    {
        $this->expectException(MailInvalidArgumentException::class);
        $this->expectExceptionMessage('unknown option "user"');

        Plain::fromIterable(['user' => 'orders', 'password' => self::AUTH_VALUE]);
    }

    /**
     * NUL separates the fields of a PLAIN response, so a NUL in the password would forge one.
     */
    #[Test]
    public function rejectsNulInPasswordAgainstFieldInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The PLAIN password must not contain NUL');

        new Plain('orders', "secret\0admin");
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

        new Plain($username, $password);
    }

    #[DataProvider('dumpProvider')]
    #[Test]
    public function keepsPasswordOutOfDumps(string $function): void
    {
        static::assertStringNotContainsString(self::AUTH_VALUE, self::dump(
            $function,
            new Plain('orders', self::AUTH_VALUE),
        ));
    }

    #[Test]
    public function showsUsernameInDumps(): void
    {
        static::assertSame(
            ['username' => 'orders', 'password' => Credentials::HIDDEN],
            (new Plain('orders', self::AUTH_VALUE))->__debugInfo(),
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidCredentialsProvider(): array
    {
        return [
            'empty username'   => ['', self::AUTH_VALUE, 'PLAIN authentication requires a username'],
            'NUL in username'  => [
                "orders\0admin",
                self::AUTH_VALUE,
                'The PLAIN username must not contain control characters',
            ],
            'CRLF in username' => [
                "orders\r\nQUIT",
                self::AUTH_VALUE,
                'The PLAIN username must not contain control characters',
            ],
            'DEL in username'  => [
                "orders\x7F",
                self::AUTH_VALUE,
                'The PLAIN username must not contain control characters',
            ],
            'empty password'   => ['orders', '', 'PLAIN authentication requires a password'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dumpProvider(): array
    {
        return [
            'var_dump' => ['var_dump'],
            'print_r'  => ['print_r'],
        ];
    }

    private static function dump(string $function, object $value): string
    {
        ob_start();
        'var_dump' === $function ? var_dump($value) : print_r($value);

        return (string) ob_get_clean();
    }
}
