<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Sasl;

use Contenir\Mail\Exception\InvalidArgumentException as MailInvalidArgumentException;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Sasl\Authentication;
use Contenir\Mail\Protocol\Sasl\Reply;
use Contenir\Mail\Protocol\Sasl\ScramSha256;
use Contenir\Mail\Protocol\Smtp\Auth\CallbackChannel;
use Contenir\Mail\Protocol\Smtp\Auth\Credentials;
use Contenir\Mail\Protocol\Smtp\Auth\SaslAuthenticator;
use Contenir\Mail\Tests\TestAsset\ScramVector;
use Contenir\Mail\Tests\TestAsset\ScriptedChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

use function array_key_last;

/**
 * AUTH SCRAM-SHA-256, replaying the test vector of RFC 7677.
 */
#[CoversClass(ScramSha256::class)]
#[CoversClass(SaslAuthenticator::class)]
#[CoversClass(Authentication::class)]
#[CoversClass(Reply::class)]
#[Group('unit')]
final class ScramSha256Test extends TestCase
{
    #[Test]
    public function provesThePasswordAndChecksTheServersProof(): void
    {
        $channel = new ScriptedChannel(
            '',
            ScramVector::b64(ScramVector::SERVER_FIRST),
            ScramVector::b64(ScramVector::SERVER_FINAL),
            [235, '2.7.0 Authentication successful'],
        );

        ScramVector::authenticator()->authenticate($channel);

        static::assertSame(
            [
                ['line' => 'AUTH SCRAM-SHA-256', 'expect' => 334, 'secret' => false],
                ['line' => ScramVector::b64(ScramVector::CLIENT_FIRST), 'expect' => 334, 'secret' => true],
                ['line' => ScramVector::b64(ScramVector::CLIENT_FINAL), 'expect' => 334, 'secret' => true],
                ['line' => '', 'expect' => 334, 'secret' => true],
            ],
            $channel->steps(),
        );
    }

    #[Test]
    #[DataProvider('refusedStepProvider')]
    public function cancelsTheExchangeWhenAStepIsRefused(
        string $serverFirst,
        string $serverFinal,
        string $message,
    ): void {
        $channel = new ScriptedChannel('', ScramVector::b64($serverFirst), ScramVector::b64($serverFinal));

        try {
            ScramVector::authenticator()->authenticate($channel);
            static::fail('The refused step was not reported');
        } catch (RuntimeException $e) {
            $steps = $channel->steps();
            static::assertSame(
                [['line' => '*', 'expect' => 501, 'secret' => false], $message],
                [$steps[array_key_last($steps)], $e->getMessage()],
            );
        }
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refusedStepProvider(): array
    {
        return [
            'server-first'        => [
                'r=other,s=' . ScramVector::SALT . ',i=4096',
                ScramVector::SERVER_FINAL,
                "The server's SCRAM-SHA-256 nonce does not extend the client's",
            ],
            'the wrong signature' => [
                ScramVector::SERVER_FIRST,
                'v=' . ScramVector::SALT,
                "The server's SCRAM-SHA-256 signature does not match: it does not know the password",
            ],
        ];
    }

    #[Test]
    public function keepsTheReasonWhenTheCancellationIsAnsweredOddly(): void
    {
        $refusal = new RuntimeException('5.5.4 Unexpected', 554);
        $channel = new CallbackChannel(
            static fn(string $line): string => '*' === $line ? throw $refusal : '',
            static fn(): string => ScramVector::b64('r=other,s=' . ScramVector::SALT . ',i=4096'),
        );

        try {
            ScramVector::authenticator()->authenticate($channel);
            static::fail('The refused step was not reported');
        } catch (RuntimeException $e) {
            static::assertSame(
                ["The server's SCRAM-SHA-256 nonce does not extend the client's", $refusal],
                [$e->getMessage(), $e->getPrevious()],
            );
        }
    }

    #[Test]
    public function refusesSuccessWithoutTheServersProof(): void
    {
        $accepted = new RuntimeException('2.7.0 Authentication successful', 235);
        $channel  = new CallbackChannel(
            static fn(): string => '',
            static fn(string $line): string => ScramVector::b64(ScramVector::CLIENT_FIRST) === $line
                ? ScramVector::b64(ScramVector::SERVER_FIRST)
                : throw $accepted,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server accepted SCRAM-SHA-256 without proving it knows the password');

        ScramVector::authenticator()->authenticate($channel);
    }

    #[Test]
    public function passesOnARefusalOfTheProof(): void
    {
        $refused = new RuntimeException('5.7.8 Authentication failed', 535);
        $channel = new CallbackChannel(
            static fn(): string => '',
            static fn(string $line): string => ScramVector::b64(ScramVector::CLIENT_FIRST) === $line
                ? ScramVector::b64(ScramVector::SERVER_FIRST)
                : throw $refused,
        );

        try {
            ScramVector::authenticator()->authenticate($channel);
            static::fail('The refusal was not passed on');
        } catch (RuntimeException $e) {
            static::assertSame(
                ['5.7.8 Authentication failed', 535, $refused],
                [$e->getMessage(), $e->getCode(), $e->getPrevious()],
            );
        }
    }

    #[Test]
    public function namesTheMechanism(): void
    {
        static::assertSame('SCRAM-SHA-256', ScramVector::authenticator()->mechanism());
    }

    #[Test]
    public function startsEachExchangeWithANewNonce(): void
    {
        $auth = new ScramSha256('jo', 'secret');

        static::assertNotSame(
            ScramVector::decode($auth->start()->initialResponse()),
            ScramVector::decode($auth->start()->initialResponse()),
        );
    }

    #[Test]
    public function startsExchangesWithTheNonceGiven(): void
    {
        static::assertSame(
            ScramVector::CLIENT_FIRST,
            ScramVector::decode(ScramVector::authenticator()->start()->initialResponse()),
        );
    }

    #[Test]
    public function readsCredentialsFromSettings(): void
    {
        $auth = ScramSha256::fromIterable(['username' => 'jo@example.com', 'password' => ScramVector::PASSWORD]);

        static::assertSame(
            ['username' => 'jo@example.com', 'password' => Credentials::HIDDEN],
            $auth->__debugInfo(),
        );
    }

    #[Test]
    public function refusesUnknownSettings(): void
    {
        $this->expectException(MailInvalidArgumentException::class);
        $this->expectExceptionMessage('"access_token"');

        ScramSha256::fromIterable(['username' => 'jo', 'access_token' => ScramVector::PASSWORD]);
    }

    #[Test]
    #[DataProvider('invalidCredentialsProvider')]
    public function refusesInvalidCredentials(
        string $username,
        #[SensitiveParameter]
        string $password,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ScramSha256($username, $password);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidCredentialsProvider(): array
    {
        return [
            'no username'               => ['', 'secret', 'SCRAM-SHA-256 authentication requires a username'],
            'a control in the username' => [
                "jo\r\n",
                'secret',
                'The SCRAM-SHA-256 username must not contain control characters',
            ],
            'no password'               => ['jo', '', 'SCRAM-SHA-256 authentication requires a password'],
        ];
    }

    #[Test]
    #[RequiresPhpExtension('intl')]
    #[DataProvider('unpreparedCredentialsProvider')]
    public function refusesCredentialsThatCannotBePrepared(
        string $username,
        #[SensitiveParameter]
        string $password,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ScramSha256($username, $password);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function unpreparedCredentialsProvider(): array
    {
        return [
            'a username not in UTF-8'   => [
                "jo\xFF",
                'secret',
                'The SCRAM username must be UTF-8 text without control characters',
            ],
            'a control in the password' => [
                'jo',
                "sec\x01ret",
                'The SCRAM password must be UTF-8 text without control characters',
            ],
        ];
    }
}
