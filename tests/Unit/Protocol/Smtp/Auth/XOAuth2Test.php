<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp\Auth;

use Contenir\Mail\Exception\InvalidArgumentException as MailInvalidArgumentException;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
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
