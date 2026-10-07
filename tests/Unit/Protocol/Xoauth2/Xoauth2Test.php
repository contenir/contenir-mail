<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Xoauth2;

use Contenir\Mail\Protocol\Xoauth2\Xoauth2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Contenir\Mail\Protocol\Xoauth2\Xoauth2::class)]
class Xoauth2Test extends TestCase
{
    /** @psalm-suppress InternalClass */
    #[Test]
    public function encodeXoauth2Sasl(): void
    {
        $accessToken = 'dXNlcj10ZXN0QGNvbnRvc28ub25taWNyb3NvZnQuY29tAWF1dGg9QmVhcmVyIEV3QkFBbDNCQUFVRkZwVUFvN';
        $accessToken .= '0ozVmUwYmpMQldaV0NjbFJDM0VvQUEBAQ==';

        /**
         * @psalm-suppress InternalMethod
         */
        static::assertSame(
            $accessToken,
            Xoauth2::encodeXoauth2Sasl(
                'test@contoso.onmicrosoft.com',
                'EwBAAl3BAAUFFpUAo7J3Ve0bjLBWZWCclRC3EoAA',
            ),
        );
    }
}
