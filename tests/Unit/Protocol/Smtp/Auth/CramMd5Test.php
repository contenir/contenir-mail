<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp\Auth;

use Contenir\Mail\Exception\InvalidArgumentException as MailInvalidArgumentException;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Smtp\Auth\CramMd5;
use Contenir\Mail\Protocol\Smtp\Auth\Credentials;
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
#[CoversClass(CramMd5::class)]
#[CoversClass(Credentials::class)]
#[Group('unit')]
final class CramMd5Test extends TestCase
{
    /**
     * The worked example of RFC 2195 section 2.
     */
    private const string CHALLENGE = '<1896.697170952@postoffice.reston.mci.net>';

    private const string AUTH_VALUE = 'tanstaaftanstaaf';

    #[Test]
    public function answersChallengeWithDigestFromRfc2195(): void
    {
        $channel = new ScriptedChannel(base64_encode(self::CHALLENGE));

        (new CramMd5('tim', self::AUTH_VALUE))->authenticate($channel);

        static::assertSame(
            [
                ['line' => 'AUTH CRAM-MD5', 'expect' => 334, 'secret' => false],
                ['line' => base64_encode('tim b913a602c7eda7a495b4e6e7334d3890'), 'expect' => 235, 'secret' => true],
            ],
            $channel->steps(),
        );
    }

    #[Test]
    public function namesCramMd5Mechanism(): void
    {
        static::assertSame('CRAM-MD5', (new CramMd5('tim', self::AUTH_VALUE))->mechanism());
    }

    #[Test]
    public function readsCredentialsFromSettings(): void
    {
        $channel = new ScriptedChannel(base64_encode(self::CHALLENGE));

        CramMd5::fromIterable(['username' => 'tim', 'password' => self::AUTH_VALUE])->authenticate($channel);

        static::assertSame(base64_encode('tim b913a602c7eda7a495b4e6e7334d3890'), $channel->steps()[1]['line']);
    }

    #[Test]
    public function rejectsUnknownSetting(): void
    {
        $this->expectException(MailInvalidArgumentException::class);
        $this->expectExceptionMessage('unknown option "secret"');

        CramMd5::fromIterable(['username' => 'tim', 'secret' => self::AUTH_VALUE]);
    }

    #[DataProvider('invalidChallengeProvider')]
    #[Test]
    public function rejectsInvalidChallenge(string $challenge): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server sent an invalid CRAM-MD5 challenge');

        (new CramMd5('tim', self::AUTH_VALUE))->authenticate(new ScriptedChannel($challenge));
    }

    #[Test]
    public function sendsNoCredentialsForInvalidChallenge(): void
    {
        $channel = new ScriptedChannel('not base64!');

        try {
            (new CramMd5('tim', self::AUTH_VALUE))->authenticate($channel);
        } catch (RuntimeException) {
            static::assertCount(1, $channel->steps());
            return;
        }

        static::fail('An invalid challenge was answered');
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

        new CramMd5($username, $password);
    }

    #[Test]
    public function keepsPasswordOutOfDumps(): void
    {
        ob_start();
        var_dump(new CramMd5('tim', self::AUTH_VALUE));

        static::assertStringNotContainsString(self::AUTH_VALUE, (string) ob_get_clean());
    }

    #[Test]
    public function showsUsernameInDumps(): void
    {
        static::assertSame(
            ['username' => 'tim', 'password' => Credentials::HIDDEN],
            (new CramMd5('tim', self::AUTH_VALUE))->__debugInfo(),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidChallengeProvider(): array
    {
        return [
            'empty'      => [''],
            'not base64' => ['not base64!'],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidCredentialsProvider(): array
    {
        return [
            'empty username'  => ['', self::AUTH_VALUE, 'CRAM-MD5 authentication requires a username'],
            'tab in username' => [
                "tim\tadmin",
                self::AUTH_VALUE,
                'The CRAM-MD5 username must not contain control characters',
            ],
            'empty password'  => ['tim', '', 'CRAM-MD5 authentication requires a password'],
        ];
    }
}
