<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp\Auth;

use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Smtp\Auth\Crammd5;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Crammd5::class)]
class Crammd5Test extends TestCase
{
    /** @var Crammd5 */
    protected $auth;

    public function setUp(): void
    {
        $this->auth = new Crammd5();
    }

    #[Test]
    public function hmacMd5ReturnsExpectedHash(): void
    {
        $class  = new ReflectionClass(Crammd5::class);
        $method = $class->getMethod('hmacMd5');

        $result = $method->invokeArgs(
            $this->auth,
            ['frodo', 'speakfriendandenter'],
        );

        static::assertSame('be56fa81a5671e0c62e00134180aae2c', $result);
    }

    #[Test]
    public function anExceptionIsThrownForEmptyPassword(): void
    {
        $class  = new ReflectionClass(Crammd5::class);
        $method = $class->getMethod('hmacMd5');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CramMD5 authentication requires a non-empty password');
        $method->invokeArgs(
            $this->auth,
            ['', 'data'],
        );
    }

    #[Test]
    public function anExceptionIsThrownForEmptyChallenge(): void
    {
        $class  = new ReflectionClass(Crammd5::class);
        $method = $class->getMethod('hmacMd5');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CramMD5 authentication requires a non-empty challenge');
        $method->invokeArgs(
            $this->auth,
            ['foo', ''],
        );
    }

    #[Test]
    public function usernameAccessors(): void
    {
        $this->auth->setUsername('test');
        static::assertSame('test', $this->auth->getUsername());
    }

    #[Test]
    public function passwordAccessors(): void
    {
        $this->auth->setPassword('test');
        static::assertSame('test', $this->auth->getPassword());
    }
}
