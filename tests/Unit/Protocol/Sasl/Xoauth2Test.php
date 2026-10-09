<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Sasl;

use Closure;
use Contenir\Mail\Exception\InvalidArgumentException as MailInvalidArgumentException;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Sasl\Authentication;
use Contenir\Mail\Protocol\Sasl\Reply;
use Contenir\Mail\Protocol\Sasl\Xoauth2;
use Contenir\Mail\Protocol\Sasl\Xoauth2Exchange;
use Contenir\Mail\Protocol\Smtp\Auth\Credentials;
use Contenir\Mail\Protocol\Smtp\Auth\SaslAuthenticator;
use Contenir\Mail\Tests\TestAsset\ScriptedChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

use function base64_encode;
use function count;
use function ob_get_clean;
use function ob_start;
use function var_dump;

/**
 * @mago-expect lint:no-debug-symbols var_dump() and print_r() are what these tests prove keep secrets hidden.
 */
#[CoversClass(Xoauth2::class)]
#[CoversClass(Xoauth2Exchange::class)]
#[CoversClass(SaslAuthenticator::class)]
#[CoversClass(Authentication::class)]
#[CoversClass(Reply::class)]
#[CoversClass(Credentials::class)]
#[Group('unit')]
final class Xoauth2Test extends TestCase
{
    private const string AUTH_VALUE = 'ya29.vF9dft4qmTc2Nvb3RlckBhdHRhdmlzdGEuY29tCg';

    private const array ACCEPTED = [235, '2.7.0 Accepted'];

    #[Test]
    public function sendsBearerTokenAsSecretResponse(): void
    {
        $channel = new ScriptedChannel('', self::ACCEPTED);

        (new Xoauth2('jo@example.com', self::AUTH_VALUE))->authenticate($channel);

        static::assertSame(
            [
                ['line' => 'AUTH XOAUTH2', 'expect' => 334, 'secret' => false],
                [
                    'line'   => base64_encode("user=jo@example.com\x01auth=Bearer " . self::AUTH_VALUE . "\x01\x01"),
                    'expect' => 334,
                    'secret' => true,
                ],
            ],
            $channel->steps(),
        );
    }

    #[Test]
    public function endsTheExchangeAndReportsTheStatusWhenTheTokenIsRefused(): void
    {
        $channel = new ScriptedChannel(
            '',
            base64_encode('{"status":"401","schemes":"bearer"}'),
            [535, '5.7.8 Username and Password not accepted'],
        );

        try {
            (new Xoauth2('jo@example.com', self::AUTH_VALUE))->authenticate($channel);
            static::fail('The refused token was not reported');
        } catch (RuntimeException $e) {
            static::assertSame(
                [
                    ['line' => '', 'expect' => 334, 'secret' => true],
                    'The server refused the access token (status 401): 5.7.8 Username and Password not accepted',
                    535,
                ],
                [$channel->steps()[2], $e->getMessage(), $e->getCode()],
            );
        }
    }

    #[Test]
    public function passesOnOtherFailuresWithoutAnEmptyResponse(): void
    {
        $channel = new ScriptedChannel('', [535, '5.7.8 Username and Password not accepted']);

        try {
            (new Xoauth2('jo@example.com', self::AUTH_VALUE))->authenticate($channel);
            static::fail('The failure was not passed on');
        } catch (RuntimeException $e) {
            static::assertSame(
                [2, '5.7.8 Username and Password not accepted', 535],
                [count($channel->steps()), $e->getMessage(), $e->getCode()],
            );
        }
    }

    #[Test]
    public function cancelsASecondChallenge(): void
    {
        $channel = new ScriptedChannel('', base64_encode('{"status":"401"}'), 'more', [501, 'Cancelled']);

        try {
            (new Xoauth2('jo@example.com', self::AUTH_VALUE))->authenticate($channel);
            static::fail('The second challenge was answered');
        } catch (RuntimeException $e) {
            static::assertSame(
                [['line' => '*', 'expect' => 501, 'secret' => false], 'The server sent XOAUTH2 a second challenge'],
                [$channel->steps()[3], $e->getMessage()],
            );
        }
    }

    #[Test]
    public function refusesAcceptanceAfterTheServerRefusedTheToken(): void
    {
        $channel = new ScriptedChannel('', base64_encode('{"status":"401"}'), self::ACCEPTED);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^The server refused the access token \(status 401\)$/D');

        (new Xoauth2('jo@example.com', self::AUTH_VALUE))->authenticate($channel);
    }

    #[Test]
    public function givesTheInitialResponseItSends(): void
    {
        static::assertSame(
            base64_encode("user=jo@example.com\x01auth=Bearer " . self::AUTH_VALUE . "\x01\x01"),
            (new Xoauth2('jo@example.com', self::AUTH_VALUE))->initialResponse(),
        );
    }

    #[Test]
    #[DataProvider('refusalProvider')]
    public function describesARefusal(?string $challenge, string $reason, string $message): void
    {
        $exchange = (new Xoauth2('jo@example.com', self::AUTH_VALUE))->start();
        if (null !== $challenge) {
            $exchange->respond($challenge);
        }

        static::assertSame($message, $exchange->refusal($reason));
    }

    /**
     * @return array<string, array{string|null, string, string}>
     */
    public static function refusalProvider(): array
    {
        $challenge = base64_encode('{"status":"401"}');

        return [
            'outright, with a reason'      => [null, '5.7.8 Invalid', '5.7.8 Invalid'],
            'outright, without a reason'   => [null, '', 'The server refused the access token'],
            'after a challenge'            => [
                $challenge,
                '5.7.8 Invalid',
                'The server refused the access token (status 401): 5.7.8 Invalid',
            ],
            'after a challenge, no reason' => [$challenge, '', 'The server refused the access token (status 401)'],
        ];
    }

    #[Test]
    public function keepsTheResponseOutOfDumpsOfTheExchange(): void
    {
        ob_start();
        var_dump((new Xoauth2('jo@example.com', self::AUTH_VALUE))->start());

        static::assertStringNotContainsString(self::AUTH_VALUE, (string) ob_get_clean());
    }

    #[Test]
    public function namesTheXoauth2Mechanism(): void
    {
        static::assertSame('XOAUTH2', (new Xoauth2('jo@example.com', self::AUTH_VALUE))->mechanism());
    }

    #[Test]
    public function readsCredentialsFromSettings(): void
    {
        $channel = new ScriptedChannel('', self::ACCEPTED);

        Xoauth2::fromIterable(['username' => 'jo@example.com', 'access_token' => self::AUTH_VALUE])->authenticate(
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

        Xoauth2::fromIterable(['username' => 'jo@example.com', 'password' => self::AUTH_VALUE]);
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

        new Xoauth2($username, $token);
    }

    #[Test]
    public function keepsTokenOutOfDumps(): void
    {
        ob_start();
        var_dump(new Xoauth2('jo@example.com', self::AUTH_VALUE));

        static::assertStringNotContainsString(self::AUTH_VALUE, (string) ob_get_clean());
    }

    #[Test]
    public function showsUsernameInDumps(): void
    {
        static::assertSame(
            ['username' => 'jo@example.com', 'accessToken' => Credentials::HIDDEN],
            (new Xoauth2('jo@example.com', self::AUTH_VALUE))->__debugInfo(),
        );
    }

    #[Test]
    public function sendsTokenFromProvider(): void
    {
        $channel = new ScriptedChannel('', self::ACCEPTED);

        (new Xoauth2('jo@example.com', static fn(): string => self::AUTH_VALUE))->authenticate($channel);

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
        $authenticator = new Xoauth2('jo@example.com', static function () use (&$calls): string {
            $calls += 1;

            return self::AUTH_VALUE . $calls;
        });

        $channel = new ScriptedChannel('', self::ACCEPTED, '', self::ACCEPTED);
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
        new Xoauth2('jo@example.com', static function () use (&$calls): string {
            $calls += 1;

            return self::AUTH_VALUE;
        });

        static::assertSame(0, $calls);
    }

    #[DataProvider('invalidProvidedTokenProvider')]
    #[Test]
    public function rejectsInvalidTokenFromProvider(#[SensitiveParameter] string $token, string $message): void
    {
        $authenticator = new Xoauth2('jo@example.com', static fn(): string => $token);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $authenticator->authenticate(new ScriptedChannel());
    }

    #[Test]
    public function sendsNothingWhenProviderFails(): void
    {
        $channel       = new ScriptedChannel();
        $authenticator = new Xoauth2('jo@example.com', static fn(): string => '');

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
        $authenticator = new Xoauth2('jo@example.com', $provider);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The XOAUTH2 access token provider must return a string, got null');

        $authenticator->authenticate(new ScriptedChannel());
    }

    #[Test]
    public function readsTokenProviderFromSettings(): void
    {
        $channel = new ScriptedChannel('', self::ACCEPTED);

        Xoauth2::fromIterable([
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

        Xoauth2::fromIterable(['username' => 'jo@example.com', 'access_token' => ['not', 'callable']]);
    }

    #[Test]
    public function rejectsMissingTokenSetting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('XOAUTH2 authentication requires an access token');

        Xoauth2::fromIterable(['username' => 'jo@example.com']);
    }

    #[Test]
    public function keepsTokenProviderOutOfDumps(): void
    {
        static::assertSame(
            ['username' => 'jo@example.com', 'accessToken' => Credentials::HIDDEN],
            (new Xoauth2('jo@example.com', static fn(): string => self::AUTH_VALUE))->__debugInfo(),
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
