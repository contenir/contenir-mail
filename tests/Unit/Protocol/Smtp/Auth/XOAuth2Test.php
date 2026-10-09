<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp\Auth;

use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Sasl\MechanismInterface;
use Contenir\Mail\Protocol\Sasl\Xoauth2 as SaslXoauth2;
use Contenir\Mail\Protocol\Smtp\Auth\AuthenticatorInterface;
use Contenir\Mail\Protocol\Smtp\Auth\Credentials;
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
use Contenir\Mail\Protocol\Xoauth2\Xoauth2 as Encoder;
use Contenir\Mail\Storage\ImapConfig;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The 0.2 name of XOAUTH2, kept for the code written for it.
 *
 * Building one is deprecated, which PHP 8.4 and later report; these tests still build them.
 */
#[CoversClass(XOAuth2::class)]
#[Group('unit')]
#[IgnoreDeprecations]
final class XOAuth2Test extends TestCase
{
    /**
     * @mago-expect lint:no-literal-password A made-up token for a scripted server.
     */
    private const string TOKEN = 'ya29.token';

    #[Test]
    #[RequiresPhp('>= 8.4')]
    public function reportsThatItIsDeprecated(): void
    {
        $this->expectUserDeprecationMessage(
            'Method Contenir\Mail\Protocol\Smtp\Auth\XOAuth2::__construct() is deprecated since 0.3.0, '
                . 'use Contenir\Mail\Protocol\Sasl\Xoauth2',
        );

        new XOAuth2('jo@example.com', self::TOKEN);
    }

    #[Test]
    public function isTheMechanismItWasRenamedTo(): void
    {
        $auth = new XOAuth2('jo@example.com', self::TOKEN);

        static::assertSame(
            [true, true, true, 'XOAUTH2'],
            [
                $auth instanceof SaslXoauth2,
                $auth instanceof MechanismInterface,
                $auth instanceof AuthenticatorInterface,
                $auth->mechanism(),
            ],
        );
    }

    #[Test]
    public function readsTheSameSettings(): void
    {
        $auth = XOAuth2::fromIterable(['username' => 'jo@example.com', 'access_token' => static fn(): string => 'x']);

        static::assertSame(
            [XOAuth2::class, 'jo@example.com', ['username' => 'jo@example.com', 'accessToken' => Credentials::HIDDEN]],
            [$auth::class, $auth->username, $auth->__debugInfo()],
        );
    }

    #[Test]
    public function refusesUnknownSettings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(XOAuth2::class . ': unknown option "password"');

        XOAuth2::fromIterable(['username' => 'jo@example.com', 'password' => self::TOKEN]);
    }

    #[Test]
    public function signsInToImap(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 SASL-IR AUTH=XOAUTH2\r\nTAG1 OK\r\n")
            ->expect('TAG2 AUTHENTICATE XOAUTH2 ' . Encoder::encodeXoauth2Sasl('jo@example.com', self::TOKEN) . "\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();

        ScriptedServer::imap($server)->authenticate(new XOAuth2('jo@example.com', self::TOKEN));

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function isTakenByTheMailboxSettings(): void
    {
        $auth = new XOAuth2('jo@example.com', self::TOKEN);

        static::assertSame($auth, ImapConfig::fromIterable(['host' => 'imap.example.com', 'auth' => $auth])->auth);
    }
}
