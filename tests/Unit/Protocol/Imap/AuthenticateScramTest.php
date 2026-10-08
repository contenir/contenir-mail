<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use Contenir\Mail\Tests\Unit\TestAsset\ScramVector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_quote;

/**
 * IMAP AUTHENTICATE with SCRAM-SHA-256, replaying the test vector of RFC 7677.
 */
#[CoversClass(Imap::class)]
#[Group('unit')]
final class AuthenticateScramTest extends TestCase
{
    private static function server(string $capabilities): InMemoryConnection
    {
        return ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY {$capabilities}\r\nTAG1 OK\r\n");
    }

    /**
     * A server that offers SASL-IR and has answered client-first with server-first.
     */
    private static function afterServerFirst(): InMemoryConnection
    {
        return self::server('IMAP4rev1 SASL-IR AUTH=SCRAM-SHA-256')
            ->expect('TAG2 AUTHENTICATE SCRAM-SHA-256 ' . ScramVector::b64(ScramVector::CLIENT_FIRST) . "\r\n")
            ->reply('+ ' . ScramVector::b64(ScramVector::SERVER_FIRST) . "\r\n")
            ->expect(ScramVector::b64(ScramVector::CLIENT_FINAL) . "\r\n");
    }

    /**
     * A server that has also sent server-final, which the client accepts.
     */
    private static function afterServerFinal(): InMemoryConnection
    {
        return self::afterServerFirst()
            ->reply('+ ' . ScramVector::b64(ScramVector::SERVER_FINAL) . "\r\n")
            ->expect("\r\n");
    }

    #[Test]
    public function sendsClientFirstWithTheCommandWhenTheServerOffersSaslIr(): void
    {
        $server = self::afterServerFinal()->reply("TAG2 OK Logged in\r\n")->hangUp();

        ScriptedServer::imap($server)->authenticate(ScramVector::authenticator());

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function sendsClientFirstAfterTheContinuationWithoutSaslIr(): void
    {
        $server = self::server('IMAP4rev1 AUTH=SCRAM-SHA-256')
            ->expect("TAG2 AUTHENTICATE SCRAM-SHA-256\r\n")
            ->reply("+ \r\n")
            ->expect(ScramVector::b64(ScramVector::CLIENT_FIRST) . "\r\n")
            ->reply('+ ' . ScramVector::b64(ScramVector::SERVER_FIRST) . "\r\n")
            ->expect(ScramVector::b64(ScramVector::CLIENT_FINAL) . "\r\n")
            ->reply('+ ' . ScramVector::b64(ScramVector::SERVER_FINAL) . "\r\n")
            ->expect("\r\n")
            ->reply("TAG2 ok Logged in\r\n")
            ->hangUp();

        ScriptedServer::imap($server)->authenticate(ScramVector::authenticator());

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function readsAContinuationWithoutTheSpace(): void
    {
        $server = self::server('IMAP4rev1 SASL-IR AUTH=SCRAM-SHA-256')
            ->expect('TAG2 AUTHENTICATE SCRAM-SHA-256 ' . ScramVector::b64(ScramVector::CLIENT_FIRST) . "\r\n")
            ->reply('+' . ScramVector::b64(ScramVector::SERVER_FIRST) . "\r\n")
            ->expect(ScramVector::b64(ScramVector::CLIENT_FINAL) . "\r\n")
            ->reply('+' . ScramVector::b64(ScramVector::SERVER_FINAL) . "\r\n")
            ->expect("\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();

        ScriptedServer::imap($server)->authenticate(ScramVector::authenticator());

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function enablesImap4Rev2AfterSigningIn(): void
    {
        $server = self::server('IMAP4rev1 IMAP4rev2 SASL-IR AUTH=SCRAM-SHA-256')
            ->expect('TAG2 AUTHENTICATE SCRAM-SHA-256 ' . ScramVector::b64(ScramVector::CLIENT_FIRST) . "\r\n")
            ->reply('+ ' . ScramVector::b64(ScramVector::SERVER_FIRST) . "\r\n")
            ->expect(ScramVector::b64(ScramVector::CLIENT_FINAL) . "\r\n")
            ->reply('+ ' . ScramVector::b64(ScramVector::SERVER_FINAL) . "\r\n")
            ->expect("\r\n")
            ->reply("TAG2 OK Logged in\r\n")
            ->expect("TAG3 ENABLE IMAP4rev2\r\n")
            ->reply("* ENABLED IMAP4rev2\r\nTAG3 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        $imap->authenticate(ScramVector::authenticator());

        static::assertSame([true, true], [$imap->usesUtf8MailboxNames(), $server->isScriptComplete()]);
    }

    #[Test]
    public function asksForCapabilitiesAgainAfterSigningIn(): void
    {
        $server = self::afterServerFinal()
            ->reply("TAG2 OK Logged in\r\n")
            ->expect("TAG3 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 LOGINDISABLED\r\nTAG3 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        $imap->authenticate(ScramVector::authenticator());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server does not allow LOGIN on this connection (LOGINDISABLED)');

        $imap->login('jo', 'secret');
    }

    #[Test]
    public function refusesAServerThatDoesNotOfferScramSha256(): void
    {
        $imap = ScriptedServer::imap(self::server('IMAP4rev1 SASL-IR AUTH=SCRAM-SHA-1 AUTH=XOAUTH2')->hangUp());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server does not offer SCRAM-SHA-256');

        $imap->authenticate(ScramVector::authenticator());
    }

    #[Test]
    #[DataProvider('mechanismRefusalProvider')]
    public function reportsTheMechanismRefusedBeforeClientFirst(string $reply, string $message): void
    {
        $imap = ScriptedServer::imap(
            self::server('IMAP4rev1 AUTH=SCRAM-SHA-256')
                ->expect("TAG2 AUTHENTICATE SCRAM-SHA-256\r\n")
                ->reply($reply)
                ->hangUp(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, delimiter: '/') . '$/D');

        $imap->authenticate(ScramVector::authenticator());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function mechanismRefusalProvider(): array
    {
        return [
            'with a reason'    => ["TAG2 NO Unsupported mechanism\r\n", 'Unsupported mechanism'],
            'without a reason' => ["TAG2 NO\r\n", 'The server refused SCRAM-SHA-256'],
        ];
    }

    #[Test]
    #[DataProvider('refusalProvider')]
    public function reportsTheServersRefusalOfClientFirst(string $reply, string $message): void
    {
        $imap = ScriptedServer::imap(
            self::server('IMAP4rev1 SASL-IR AUTH=SCRAM-SHA-256')
                ->expect('TAG2 AUTHENTICATE SCRAM-SHA-256 ' . ScramVector::b64(ScramVector::CLIENT_FIRST) . "\r\n")
                ->reply($reply)
                ->hangUp(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, delimiter: '/') . '$/D');

        $imap->authenticate(ScramVector::authenticator());
    }

    #[Test]
    #[DataProvider('refusalProvider')]
    public function reportsTheServersRefusalOfTheProof(string $reply, string $message): void
    {
        $imap = ScriptedServer::imap(self::afterServerFirst()->reply($reply)->hangUp());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, delimiter: '/') . '$/D');

        $imap->authenticate(ScramVector::authenticator());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'with a reason'     => [
                "* OK still here\r\nTAG2 NO [AUTHENTICATIONFAILED] Authentication failed.\r\n",
                '[AUTHENTICATIONFAILED] Authentication failed.',
            ],
            'without a reason'  => ["TAG2 NO\r\n", 'The server refused the credentials'],
            'success, unproved' => [
                "TAG2 OK Logged in\r\n",
                'The server ended SCRAM-SHA-256 without proving it knows the password',
            ],
        ];
    }

    #[Test]
    #[DataProvider('finalRefusalProvider')]
    public function reportsARefusalAfterTheServersProof(string $reply, string $message): void
    {
        $imap = ScriptedServer::imap(self::afterServerFinal()->reply($reply)->hangUp());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, delimiter: '/') . '$/D');

        $imap->authenticate(ScramVector::authenticator());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function finalRefusalProvider(): array
    {
        return [
            'with a reason'    => ["TAG2 NO [UNAVAILABLE] Try later\r\n", '[UNAVAILABLE] Try later'],
            'without a reason' => ["TAG2 NO\r\n", 'The server refused the credentials'],
        ];
    }

    #[Test]
    public function cancelsTheExchangeWhenServerFirstIsInvalid(): void
    {
        $server = self::server('IMAP4rev1 SASL-IR AUTH=SCRAM-SHA-256')
            ->expect('TAG2 AUTHENTICATE SCRAM-SHA-256 ' . ScramVector::b64(ScramVector::CLIENT_FIRST) . "\r\n")
            ->reply('+ ' . ScramVector::b64(ScramVector::serverFirst('100')) . "\r\n")
            ->expect("*\r\n")
            ->reply("TAG2 BAD Authentication aborted\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        try {
            $imap->authenticate(ScramVector::authenticator());
            static::fail('The invalid server-first message was accepted');
        } catch (RuntimeException $e) {
            static::assertSame(
                [
                    'The server asked for 100 SCRAM-SHA-256 iterations; between 4096 and 1000000 are accepted',
                    true,
                ],
                [$e->getMessage(), $server->isScriptComplete()],
            );
        }
    }

    #[Test]
    public function cancelsTheExchangeWhenTheServersProofDoesNotMatch(): void
    {
        $server = self::afterServerFirst()
            ->reply('+ ' . ScramVector::b64('v=' . ScramVector::SALT) . "\r\n")
            ->expect("*\r\n")
            ->reply("* OK still here\r\nTAG2 BAD Authentication aborted\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        try {
            $imap->authenticate(ScramVector::authenticator());
            static::fail('The wrong server signature was accepted');
        } catch (RuntimeException $e) {
            static::assertSame(
                ["The server's SCRAM-SHA-256 signature does not match: it does not know the password", true],
                [$e->getMessage(), $server->isScriptComplete()],
            );
        }
    }
}
