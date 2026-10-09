<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Pop3;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Protocol\Sasl\Xoauth2;
use Contenir\Mail\Protocol\Xoauth2\Xoauth2 as Encoder;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * POP3 AUTH with XOAUTH2, signing in with the same settings as SMTP and IMAP.
 */
#[CoversClass(Pop3::class)]
#[Group('unit')]
final class AuthenticateTest extends TestCase
{
    #[Test]
    public function signsInWithATokenFromAProvider(): void
    {
        $server = ScriptedServer::pop3Greeting()
            ->expect("AUTH XOAUTH2\r\n")
            ->reply("+ \r\n")
            ->expect(Encoder::encodeXoauth2Sasl('jo@example.com', 'fresh') . "\r\n")
            ->reply("+OK Welcome\r\n")
            ->hangUp();

        ScriptedServer::pop3($server)->authenticate(new Xoauth2('jo@example.com', static fn(): string => 'fresh'));

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function reportsTheMechanismRefused(): void
    {
        $pop3 = ScriptedServer::pop3(
            ScriptedServer::pop3Greeting()
                ->expect("AUTH XOAUTH2\r\n")
                ->reply("-ERR [AUTH] Unknown mechanism\r\n")
                ->hangUp(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('last request failed: [AUTH] Unknown mechanism');

        $pop3->authenticate(new Xoauth2('jo@example.com', 'token'));
    }
}
