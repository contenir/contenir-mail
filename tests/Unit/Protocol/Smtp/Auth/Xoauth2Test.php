<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp\Auth;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Smtp\Auth\Xoauth2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Xoauth2::class)]
#[Group('unit')]
final class Xoauth2Test extends TestCase
{
    #[Test]
    public function readsCredentialsFromConfig(): void
    {
        $credential = 'example-access-token';
        $auth       = new Xoauth2('mail.example.com', null, ['username' => 'user', 'access_token' => $credential]);

        static::assertSame(['user', $credential], [$auth->getUsername(), $auth->getAccessToken()]);
    }

    #[Test]
    public function setsCredentialsAfterConstruction(): void
    {
        $auth = (new Xoauth2('mail.example.com'))->setUsername('user')
            ->setAccessToken('token');

        static::assertSame(['user', 'token'], [$auth->getUsername(), $auth->getAccessToken()]);
    }

    #[Test]
    public function rejectsInvalidHost(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The input does not match the expected structure for a DNS hostname');

        new Xoauth2('invalid host name');
    }
}
