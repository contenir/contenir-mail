<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Protocol\Smtp as SmtpProtocol;
use Contenir\Mail\Protocol\Smtp\Auth\Crammd5;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Protocol\Smtp\Auth\Plain;
use Contenir\Mail\Protocol\Smtp\Auth\Xoauth2;
use Contenir\Mail\Transport\Exception\InvalidArgumentException;
use Contenir\Mail\Transport\Smtp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(Smtp::class)]
#[Group('unit')]
final class SmtpConnectionResolutionTest extends TestCase
{
    /**
     * @param class-string<SmtpProtocol> $expected
     */
    #[DataProvider('connectionProvider')]
    #[Test]
    public function createsConnectionWithoutPluginManager(string $name, string $expected): void
    {
        static::assertSame($expected, (new Smtp())->plugin($name, ['host' => 'mail.example.com'])::class);
    }

    #[Test]
    public function passesOptionsToConnection(): void
    {
        $connection = (new Smtp())->plugin('login', ['host' => 'mail.example.com', 'username' => 'user']);

        static::assertInstanceOf(Login::class, $connection);
        static::assertSame('user', $connection->getUsername());
    }

    #[Test]
    public function createsConnectionWithoutOptions(): void
    {
        static::assertSame(SmtpProtocol::class, (new Smtp())->plugin('smtp')::class);
    }

    #[Test]
    public function rejectsUnknownConnection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SMTP connection "stdClass" is not a known authentication type');

        (new Smtp())->plugin(stdClass::class);
    }

    /**
     * @return array<string, array{string, class-string<SmtpProtocol>}>
     */
    public static function connectionProvider(): array
    {
        return [
            'smtp'                  => ['smtp', SmtpProtocol::class],
            'plain'                 => ['plain', Plain::class],
            'login'                 => ['login', Login::class],
            'crammd5'               => ['crammd5', Crammd5::class],
            'xoauth2'               => ['xoauth2', Xoauth2::class],
            'mixed case alias'      => ['CramMD5', Crammd5::class],
            'connection class name' => [Login::class, Login::class],
        ];
    }
}
