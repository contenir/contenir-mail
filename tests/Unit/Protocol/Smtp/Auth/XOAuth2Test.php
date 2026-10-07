<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp\Auth;

use Closure;
use Contenir\Mail\Exception\InvalidArgumentException as MailInvalidArgumentException;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Smtp\Auth\Credentials;
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
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
use function var_dump;

/**
 * @mago-expect lint:no-debug-symbols var_dump() and print_r() are what these tests prove keep secrets hidden.
 */
#[CoversClass(XOAuth2::class)]
#[CoversClass(Credentials::class)]
#[Group('unit')]
final class XOAuth2Test extends TestCase
{
    private const string AUTH_VALUE = 'ya29.vF9dft4qmTc2Nvb3RlckBhdHRhdmlzdGEuY29tCg';

    #[Test]
    public function sendsBearerTokenAsSecretResponse(): void
    {
        $channel = new ScriptedChannel();

        (new XOAuth2('jo@example.com', self::AUTH_VALUE))->authenticate($channel);

        static::assertSame(
            [
                ['line' => 'AUTH XOAUTH2', 'expect' => 334, 'secret' => false],
                [
                    'line'   => base64_encode("user=jo@example.com\x01auth=Bearer " . self::AUTH_VALUE . "\x01\x01"),
                    'expect' => 235,
                    'secret' => true,
                ],
            ],
            $channel->steps(),
        );
    }

    #[Test]
    public function namesXOAuth2Mechanism(): void
    {
        static::assertSame('XOAUTH2', (new XOAuth2('jo@example.com', self::AUTH_VALUE))->mechanism());
    }

    #[Test]
    public function readsCredentialsFromSettings(): void
    {
        $channel = new ScriptedChannel();

        XOAuth2::fromIterable(['username' => 'jo@example.com', 'access_token' => self::AUTH_VALUE])->authenticate(
            $channel,
        );

        static::assertSame(
            base64_encode("user=jo@example.com\x01auth=Bearer " . self::AUTH_VALUE . "\x01\x01"),
            $channel->steps()[1]['line'],
        );
    }

    #[Test]
    public function rejectsUnknownSetting(): void
    {
        $this->expectException(MailInvalidArgumentException::class);
        $this->expectExceptionMessage('unknown option "password"');

        XOAuth2::fromIterable(['username' => 'jo@example.com', 'password' => self::AUTH_VALUE]);
    }

    /**
     * \x01 separates the fields of the SASL message, so one in a value would forge a field.
     */
    #[DataProvider('invalidCredentialsProvider')]
    #[Test]
    public function rejectsValuesThatCouldRewriteSaslFields(
        string $username,
        #[SensitiveParameter]
        string $token,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new XOAuth2($username, $token);
    }

    #[Test]
    public function keepsTokenOutOfDumps(): void
    {
        ob_start();
        var_dump(new XOAuth2('jo@example.com', self::AUTH_VALUE));

        static::assertStringNotContainsString(self::AUTH_VALUE, (string) ob_get_clean());
    }

    #[Test]
    public function showsUsernameInDumps(): void
    {
        static::assertSame(
            ['username' => 'jo@example.com', 'accessToken' => Credentials::HIDDEN],
            (new XOAuth2('jo@example.com', self::AUTH_VALUE))->__debugInfo(),
        );
    }

    #[Test]
    public function sendsTokenFromProvider(): void
    {
        $channel = new ScriptedChannel();

        (new XOAuth2('jo@example.com', static fn(): string => self::AUTH_VALUE))->authenticate($channel);

        static::assertSame(
            base64_encode("user=jo@example.com\x01auth=Bearer " . self::AUTH_VALUE . "\x01\x01"),
            $channel->steps()[1]['line'],
        );
    }

    /**
     * A long-running worker authenticates again with a fresh token each time.
     */
    #[Test]
    public function callsProviderOncePerAuthentication(): void
    {
        $calls         = 0;
        $authenticator = new XOAuth2('jo@example.com', static function () use (&$calls): string {
            $calls += 1;

            return self::AUTH_VALUE . $calls;
        });

        $channel = new ScriptedChannel();
        $authenticator->authenticate($channel);
        $authenticator->authenticate($channel);

        static::assertSame(
            [
                2,
                base64_encode("user=jo@example.com\x01auth=Bearer " . self::AUTH_VALUE . "1\x01\x01"),
                base64_encode("user=jo@example.com\x01auth=Bearer " . self::AUTH_VALUE . "2\x01\x01"),
            ],
            [$calls, $channel->steps()[1]['line'], $channel->steps()[3]['line']],
        );
    }

    #[Test]
    public function doesNotCallProviderBeforeAuthentication(): void
    {
        $calls = 0;
        new XOAuth2('jo@example.com', static function () use (&$calls): string {
            $calls += 1;

            return self::AUTH_VALUE;
        });

        static::assertSame(0, $calls);
    }

    #[DataProvider('invalidProvidedTokenProvider')]
    #[Test]
    public function rejectsInvalidTokenFromProvider(#[SensitiveParameter] string $token, string $message): void
    {
        $authenticator = new XOAuth2('jo@example.com', static fn(): string => $token);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $authenticator->authenticate(new ScriptedChannel());
    }

    #[Test]
    public function sendsNothingWhenProviderFails(): void
    {
        $channel       = new ScriptedChannel();
        $authenticator = new XOAuth2('jo@example.com', static fn(): string => '');

        try {
            $authenticator->authenticate($channel);
        } catch (InvalidArgumentException) {
            static::assertSame([], $channel->steps());

            return;
        }

        static::fail('The empty token was accepted');
    }

    #[Test]
    public function rejectsProviderReturningNonString(): void
    {
        /** @var Closure(): string $provider */
        $provider      = static fn(): mixed => null;
        $authenticator = new XOAuth2('jo@example.com', $provider);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The XOAUTH2 access token provider must return a string, got null');

        $authenticator->authenticate(new ScriptedChannel());
    }

    #[Test]
    public function readsTokenProviderFromSettings(): void
    {
        $channel = new ScriptedChannel();

        XOAuth2::fromIterable([
            'username'     => 'jo@example.com',
            'access_token' => static fn(): string => self::AUTH_VALUE,
        ])->authenticate($channel);

        static::assertSame(
            base64_encode("user=jo@example.com\x01auth=Bearer " . self::AUTH_VALUE . "\x01\x01"),
            $channel->steps()[1]['line'],
        );
    }

    #[Test]
    public function rejectsTokenSettingThatIsNeitherStringNorCallable(): void
    {
        $this->expectException(MailInvalidArgumentException::class);
        $this->expectExceptionMessage(
            'option "access_token" must be a string, a Closure or an invokable object, got array',
        );

        XOAuth2::fromIterable(['username' => 'jo@example.com', 'access_token' => ['not', 'callable']]);
    }

    #[Test]
    public function rejectsMissingTokenSetting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('XOAUTH2 authentication requires an access token');

        XOAuth2::fromIterable(['username' => 'jo@example.com']);
    }

    #[Test]
    public function keepsTokenProviderOutOfDumps(): void
    {
        static::assertSame(
            ['username' => 'jo@example.com', 'accessToken' => Credentials::HIDDEN],
            (new XOAuth2('jo@example.com', static fn(): string => self::AUTH_VALUE))->__debugInfo(),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidProvidedTokenProvider(): array
    {
        return [
            'empty'     => ['', 'XOAUTH2 authentication requires an access token'],
            'separator' => ["x\x01\x01", 'The XOAUTH2 access token must not contain control characters'],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidCredentialsProvider(): array
    {
        return [
            'separator in username' => [
                "jo@example.com\x01auth=Bearer x",
                self::AUTH_VALUE,
                'The XOAUTH2 username must not contain control characters',
            ],
            'separator in token'    => [
                'jo@example.com',
                "x\x01\x01",
                'The XOAUTH2 access token must not contain control characters',
            ],
            'DEL in token'          => [
                'jo@example.com',
                "x\x7F",
                'The XOAUTH2 access token must not contain control characters',
            ],
            'empty username'        => ['', self::AUTH_VALUE, 'XOAUTH2 authentication requires a username'],
            'empty token'           => ['jo@example.com', '', 'XOAUTH2 authentication requires an access token'],
        ];
    }
}
