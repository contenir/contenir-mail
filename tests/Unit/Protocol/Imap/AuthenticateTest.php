<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\Sasl\Xoauth2;
use Contenir\Mail\Protocol\Xoauth2\Xoauth2 as Encoder;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function preg_quote;

/**
 * IMAP AUTHENTICATE with XOAUTH2 (laminas/laminas-mail#197, contenir/contenir-mail#32).
 */
#[CoversClass(Imap::class)]
#[Group('unit')]
final class AuthenticateTest extends TestCase
{
    private const string USER = 'jo@example.com';

    /**
     * @mago-expect lint:no-literal-password A made-up token for a scripted server.
     */
    private const string TOKEN = 'ya29.token';

    private static function sasl(): string
    {
        return Encoder::encodeXoauth2Sasl(self::USER, self::TOKEN);
    }

    private static function server(string $capabilities): InMemoryConnection
    {
        return ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY {$capabilities}\r\nTAG1 OK\r\n");
    }

    private static function auth(): Xoauth2
    {
        return new Xoauth2(self::USER, self::TOKEN);
    }

    #[Test]
    public function sendsTheTokenWithTheCommandWhenTheServerOffersSaslIr(): void
    {
        $server = self::server('IMAP4rev1 SASL-IR AUTH=XOAUTH2')
            ->expect('TAG2 AUTHENTICATE XOAUTH2 ' . self::sasl() . "\r\n")
            ->reply("* CAPABILITY IMAP4rev1\r\nTAG2 OK Success\r\n")
            ->hangUp();

        ScriptedServer::imap($server)->authenticate(self::auth());

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function sendsTheTokenAfterTheContinuationWithoutSaslIr(): void
    {
        $server = self::server('IMAP4rev1 AUTH=XOAUTH2')
            ->expect("TAG2 AUTHENTICATE XOAUTH2\r\n")
            ->reply("+ \r\n")
            ->expect(self::sasl() . "\r\n")
            ->reply("TAG2 OK Success\r\n")
            ->hangUp();

        ScriptedServer::imap($server)->authenticate(self::auth());

        static::assertTrue($server->isScriptComplete());
    }

    /**
     * Status words are case-insensitive (RFC 3501, section 9).
     */
    #[Test]
    public function acceptsAStatusInLowerCase(): void
    {
        $server = self::server('IMAP4rev1 SASL-IR AUTH=XOAUTH2')
            ->expect('TAG2 AUTHENTICATE XOAUTH2 ' . self::sasl() . "\r\n")
            ->reply("TAG2 ok signed in\r\n")
            ->hangUp();

        ScriptedServer::imap($server)->authenticate(self::auth());

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function asksForCapabilitiesOnceForSeveralAttempts(): void
    {
        $server = self::server('IMAP4rev1 SASL-IR AUTH=XOAUTH2')
            ->expect('TAG2 AUTHENTICATE XOAUTH2 ' . self::sasl() . "\r\n")
            ->reply("TAG2 NO [AUTHENTICATIONFAILED] Invalid\r\n")
            ->expect('TAG3 AUTHENTICATE XOAUTH2 ' . self::sasl() . "\r\n")
            ->reply("TAG3 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        try {
            $imap->authenticate(self::auth());
            static::fail('The first attempt was not refused');
        } catch (RuntimeException) {
            $imap->authenticate(self::auth());
        }

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function asksForCapabilitiesAgainAfterSigningIn(): void
    {
        $server = self::server('IMAP4rev1 SASL-IR AUTH=XOAUTH2')
            ->expect('TAG2 AUTHENTICATE XOAUTH2 ' . self::sasl() . "\r\n")
            ->reply("TAG2 OK Success\r\n")
            ->expect("TAG3 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 LOGINDISABLED\r\nTAG3 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        $imap->authenticate(self::auth());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server does not allow LOGIN on this connection (LOGINDISABLED)');

        $imap->login('jo', 'secret');
    }

    #[Test]
    public function endsTheExchangeAndReportsTheServersReasonWhenTheTokenIsRefused(): void
    {
        $server = self::server('IMAP4rev1 SASL-IR AUTH=XOAUTH2')
            ->expect('TAG2 AUTHENTICATE XOAUTH2 ' . self::sasl() . "\r\n")
            ->reply('+ ' . base64_encode('{"status":"400","schemes":"Bearer"}') . "\r\n")
            ->expect("\r\n")
            ->reply("* BYE not this time\r\nTAG2 NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/^'
                . preg_quote(
                    'The server refused the access token (status 400): [AUTHENTICATIONFAILED] Invalid credentials (Failure)',
                    delimiter: '/',
                )
                . '$/D',
        );

        $imap->authenticate(self::auth());
    }

    #[Test]
    #[DataProvider('outrightRefusalProvider')]
    public function reportsATokenRefusedOutright(string $reply, string $message): void
    {
        $server = self::server('IMAP4rev1 SASL-IR AUTH=XOAUTH2')
            ->expect('TAG2 AUTHENTICATE XOAUTH2 ' . self::sasl() . "\r\n")
            ->reply($reply)
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, delimiter: '/') . '$/D');

        $imap->authenticate(self::auth());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function outrightRefusalProvider(): array
    {
        return [
            'with a reason'      => ["TAG2 NO [AUTHENTICATIONFAILED] Invalid\r\n", '[AUTHENTICATIONFAILED] Invalid'],
            'without a reason'   => ["TAG2 NO\r\n", 'The server refused the access token'],
            'a bad command'      => ["TAG2 BAD parse error\r\n", 'parse error'],
            'control characters' => ["TAG2 NO a\x1B[31mb\r\n", 'a [31mb'],
        ];
    }

    #[Test]
    #[DataProvider('mechanismRefusalProvider')]
    public function reportsTheMechanismRefusedBeforeTheToken(string $reply, string $message): void
    {
        $server = self::server('IMAP4rev1 AUTH=XOAUTH2')
            ->expect("TAG2 AUTHENTICATE XOAUTH2\r\n")
            ->reply($reply)
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, delimiter: '/') . '$/D');

        $imap->authenticate(self::auth());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function mechanismRefusalProvider(): array
    {
        return [
            'with a reason'    => ["* OK still here\r\nTAG2 NO Unsupported mechanism\r\n", 'Unsupported mechanism'],
            'without a reason' => ["TAG2 NO\r\n", 'The server refused XOAUTH2'],
        ];
    }

    #[Test]
    public function refusesAServerThatDoesNotOfferXoauth2(): void
    {
        $imap = ScriptedServer::imap(self::server('IMAP4rev1 AUTH=PLAIN')->hangUp());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server does not offer XOAUTH2');

        $imap->authenticate(self::auth());
    }
}
