<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Xoauth2;

use Contenir\Mail\Protocol\Xoauth2\Xoauth2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_encode;

#[CoversClass(Xoauth2::class)]
#[Group('unit')]
final class Xoauth2Test extends TestCase
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

    #[Test]
    #[DataProvider('refusalProvider')]
    public function describesARefusedToken(string $challenge, string $expected): void
    {
        static::assertSame($expected, Xoauth2::refusal($challenge));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'status from the JSON'     => [
                base64_encode('{"status":"401","schemes":"bearer","scope":"https://mail.google.com/"}'),
                'The server refused the access token (status 401)',
            ],
            'control characters in it' => [
                base64_encode('{"status":"invalid\u001b[31m"}'),
                'The server refused the access token (status invalid [31m)',
            ],
            'no status'                => [
                base64_encode('{"schemes":"bearer"}'),
                'The server refused the access token',
            ],
            'not JSON'                 => [base64_encode('denied'), 'The server refused the access token'],
            'not base64'               => ['not base64!', 'The server refused the access token'],
        ];
    }
}
