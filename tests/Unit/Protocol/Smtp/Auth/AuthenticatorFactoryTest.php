<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp\Auth;

use ArrayIterator;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Sasl\ScramSha256;
use Contenir\Mail\Protocol\Sasl\Xoauth2;
use Contenir\Mail\Protocol\Smtp\Auth\AuthenticatorFactory;
use Contenir\Mail\Protocol\Smtp\Auth\CramMd5;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Protocol\Smtp\Auth\Plain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
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

    /**
     * @param class-string $expected
     */
    #[DataProvider('deprecatedTypeProvider')]
    #[IgnoreDeprecations]
    #[Test]
    public function buildsAuthenticatorOfDeprecatedSpelling(string $type, string $name, string $expected): void
    {
        $this->expectUserDeprecationMessage("SMTP authentication: type \"{$type}\" is deprecated; use \"{$name}\"");

        $authenticator = AuthenticatorFactory::fromIterable([
            'type'     => $type,
            'username' => 'orders',
            'password' => self::AUTH_VALUE,
        ]);

        static::assertSame($expected, $authenticator::class);
    }

    /**
     * @return array<string, array{string, string, class-string}>
     */
    public static function deprecatedTypeProvider(): array
    {
        return [
            'crammd5'       => ['crammd5', 'cram-md5', CramMd5::class],
            'CRAM_MD5'      => ['CRAM_MD5', 'cram-md5', CramMd5::class],
            'scramsha256'   => ['scramsha256', 'scram-sha-256', ScramSha256::class],
            'scram_sha_256' => ['scram_sha_256', 'scram-sha-256', ScramSha256::class],
        ];
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
            'SMTP authentication: option "type" must be one of plain, login, cram-md5, xoauth2, scram-sha-256, got null',
        );

        AuthenticatorFactory::fromIterable(['username' => 'orders', 'password' => self::AUTH_VALUE]);
    }

    #[Test]
    public function rejectsUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'SMTP authentication: unknown type "gssapi"; expected one of plain, login, cram-md5, xoauth2, scram-sha-256',
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
            'CRAM-MD5'          => ['CRAM-MD5', 'password', CramMd5::class],
            'xoauth2'           => ['xoauth2', 'access_token', Xoauth2::class],
            'scram-sha-256'     => ['scram-sha-256', 'password', ScramSha256::class],
            'SCRAM-SHA-256'     => ['SCRAM-SHA-256', 'password', ScramSha256::class],
        ];
    }
}
