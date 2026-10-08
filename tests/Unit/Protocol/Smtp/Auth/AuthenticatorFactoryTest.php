<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp\Auth;

use ArrayIterator;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Smtp\Auth\AuthenticatorFactory;
use Contenir\Mail\Protocol\Smtp\Auth\CramMd5;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Protocol\Smtp\Auth\Plain;
use Contenir\Mail\Protocol\Smtp\Auth\ScramSha256;
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthenticatorFactory::class)]
#[Group('unit')]
final class AuthenticatorFactoryTest extends TestCase
{
    private const string AUTH_VALUE = 'not-a-real-credential';

    /**
     * @param class-string $expected
     */
    #[DataProvider('typeProvider')]
    #[Test]
    public function buildsAuthenticatorOfType(string $type, string $secretKey, string $expected): void
    {
        $authenticator = AuthenticatorFactory::fromIterable([
            'type'     => $type,
            'username' => 'orders',
            $secretKey => self::AUTH_VALUE,
        ]);

        static::assertSame($expected, $authenticator::class);
    }

    #[Test]
    public function passesOtherSettingsToAuthenticator(): void
    {
        $authenticator = AuthenticatorFactory::fromIterable(new ArrayIterator([
            'type'     => 'login',
            'username' => 'orders',
            'password' => self::AUTH_VALUE,
        ]));

        static::assertSame('orders', $authenticator instanceof Login ? $authenticator->username : null);
    }

    #[Test]
    public function rejectsMissingType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'SMTP authentication: option "type" must be one of plain, login, crammd5, xoauth2, scramsha256, got null',
        );

        AuthenticatorFactory::fromIterable(['username' => 'orders', 'password' => self::AUTH_VALUE]);
    }

    #[Test]
    public function rejectsUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'SMTP authentication: unknown type "gssapi"; expected one of plain, login, crammd5, xoauth2, scramsha256',
        );

        AuthenticatorFactory::fromIterable(['type' => 'gssapi']);
    }

    /**
     * @return array<string, array{string, string, class-string}>
     */
    public static function typeProvider(): array
    {
        return [
            'plain'             => ['plain', 'password', Plain::class],
            'login, upper case' => ['LOGIN', 'password', Login::class],
            'cram-md5'          => ['cram-md5', 'password', CramMd5::class],
            'cram_md5'          => ['CRAM_MD5', 'password', CramMd5::class],
            'xoauth2'           => ['xoauth2', 'access_token', XOAuth2::class],
            'scram-sha-256'     => ['scram-sha-256', 'password', ScramSha256::class],
            'SCRAM_SHA_256'     => ['SCRAM_SHA_256', 'password', ScramSha256::class],
        ];
    }
}
