<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Pop3;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use Contenir\Mail\Tests\Unit\TestAsset\ScramVector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * POP3 AUTH with SCRAM-SHA-256 (RFC 5034), replaying the test vector of RFC 7677.
 */
#[CoversClass(Pop3::class)]
#[Group('unit')]
final class AuthenticateScramTest extends TestCase
{
    /**
     * A server that has accepted the mechanism and client-first, and answered with server-first.
     */
    private static function afterServerFirst(): InMemoryConnection
    {
        return ScriptedServer::pop3Greeting()
            ->expect("AUTH SCRAM-SHA-256\r\n")
            ->reply("+ \r\n")
            ->expect(ScramVector::b64(ScramVector::CLIENT_FIRST) . "\r\n")
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
    public function provesThePasswordAndChecksTheServersProof(): void
    {
        $server = self::afterServerFinal()->reply("+OK Logged in.\r\n")->hangUp();

        ScriptedServer::pop3($server)->authenticate(ScramVector::authenticator());

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function reportsTheMechanismRefused(): void
    {
        $pop3 = ScriptedServer::pop3(
            ScriptedServer::pop3Greeting()
                ->expect("AUTH SCRAM-SHA-256\r\n")
                ->reply("-ERR [AUTH] Unknown mechanism\r\n")
                ->hangUp(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('last request failed: [AUTH] Unknown mechanism');

        $pop3->authenticate(ScramVector::authenticator());
    }

    #[Test]
    #[DataProvider('refusalProvider')]
    public function reportsTheServersRefusalOfTheProof(string $reply, string $message): void
    {
        $pop3 = ScriptedServer::pop3(self::afterServerFirst()->reply($reply)->hangUp());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $pop3->authenticate(ScramVector::authenticator());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'a refusal'         => [
                "-ERR [AUTH] Authentication failed.\r\n",
                'last request failed: [AUTH] Authentication failed.',
            ],
            'success, unproved' => [
                "+OK Logged in.\r\n",
                'The server ended SCRAM-SHA-256 without proving it knows the password',
            ],
        ];
    }

    #[Test]
    public function reportsARefusalAfterTheServersProof(): void
    {
        $pop3 = ScriptedServer::pop3(self::afterServerFinal()->reply("-ERR [SYS/TEMP] Try later\r\n")->hangUp());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('last request failed: [SYS/TEMP] Try later');

        $pop3->authenticate(ScramVector::authenticator());
    }

    #[Test]
    public function cancelsTheExchangeWhenServerFirstIsInvalid(): void
    {
        $server = ScriptedServer::pop3Greeting()
            ->expect("AUTH SCRAM-SHA-256\r\n")
            ->reply("+ \r\n")
            ->expect(ScramVector::b64(ScramVector::CLIENT_FIRST) . "\r\n")
            ->reply('+ ' . ScramVector::b64('r=other,s=' . ScramVector::SALT . ',i=4096') . "\r\n")
            ->expect("*\r\n")
            ->reply("-ERR Authentication aborted\r\n")
            ->hangUp();
        $pop3 = ScriptedServer::pop3($server);

        try {
            $pop3->authenticate(ScramVector::authenticator());
            static::fail('The invalid server-first message was accepted');
        } catch (RuntimeException $e) {
            static::assertSame(
                ["The server's SCRAM-SHA-256 nonce does not extend the client's", true],
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
            ->reply("-ERR Authentication aborted\r\n")
            ->hangUp();
        $pop3 = ScriptedServer::pop3($server);

        try {
            $pop3->authenticate(ScramVector::authenticator());
            static::fail('The wrong server signature was accepted');
        } catch (RuntimeException $e) {
            static::assertSame(
                ["The server's SCRAM-SHA-256 signature does not match: it does not know the password", true],
                [$e->getMessage(), $server->isScriptComplete()],
            );
        }
    }
}
