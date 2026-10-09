<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\Sasl\Authentication;
use Contenir\Mail\Protocol\Sasl\Reply;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\TestAsset\Protocol\FakeMechanism;
use Contenir\Mail\Tests\TestAsset\Protocol\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * IMAP AUTHENTICATE driven through Sasl\MechanismInterface alone, with a mechanism the client does not know.
 */
#[CoversClass(Imap::class)]
#[CoversClass(Authentication::class)]
#[CoversClass(Reply::class)]
#[Group('unit')]
final class AuthenticateMechanismTest extends TestCase
{
    private const string INITIAL = 'aW5pdGlhbA==';

    private const string CHALLENGE = 'Y2hhbGxlbmdl';

    private const string RESPONSE = 'cmVzcG9uc2U=';

    private static function server(string $capabilities): InMemoryConnection
    {
        return ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 AUTH=X-FAKE {$capabilities}\r\nTAG1 OK\r\n");
    }

    #[Test]
    public function sendsTheInitialResponseWithTheCommandUnderSaslIr(): void
    {
        $server = self::server('SASL-IR')
            ->expect('TAG2 AUTHENTICATE X-FAKE ' . self::INITIAL . "\r\n")
            ->reply('+ ' . self::CHALLENGE . "\r\n")
            ->expect(self::RESPONSE . "\r\n")
            ->reply("TAG2 OK Signed in\r\n")
            ->hangUp();
        $mechanism = new FakeMechanism(self::INITIAL, [self::RESPONSE]);

        ScriptedServer::imap($server)->authenticate($mechanism);

        static::assertSame([true, [self::CHALLENGE]], [$server->isScriptComplete(), $mechanism->challenges()]);
    }

    #[Test]
    public function sendsAnEmptyInitialResponseAsAnEqualsSignUnderSaslIr(): void
    {
        $server = self::server('SASL-IR')
            ->expect("TAG2 AUTHENTICATE X-FAKE =\r\n")
            ->reply("TAG2 OK Signed in\r\n")
            ->hangUp();

        ScriptedServer::imap($server)->authenticate(new FakeMechanism(''));

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function sendsTheInitialResponseAfterTheContinuationWithoutSaslIr(): void
    {
        $server = self::server('')
            ->expect("TAG2 AUTHENTICATE X-FAKE\r\n")
            ->reply("+ \r\n")
            ->expect(self::INITIAL . "\r\n")
            ->reply("TAG2 OK Signed in\r\n")
            ->hangUp();

        ScriptedServer::imap($server)->authenticate(new FakeMechanism(self::INITIAL));

        static::assertTrue($server->isScriptComplete());
    }

    /**
     * A mechanism without an initial response waits for the server's first challenge, SASL-IR or not.
     */
    #[Test]
    #[DataProvider('capabilityProvider')]
    public function waitsForTheFirstChallengeWithoutAnInitialResponse(string $capabilities): void
    {
        $server = self::server($capabilities)
            ->expect("TAG2 AUTHENTICATE X-FAKE\r\n")
            ->reply('+ ' . self::CHALLENGE . "\r\n")
            ->expect(self::RESPONSE . "\r\n")
            ->reply("TAG2 OK Signed in\r\n")
            ->hangUp();
        $mechanism = new FakeMechanism(null, [self::RESPONSE]);

        ScriptedServer::imap($server)->authenticate($mechanism);

        static::assertSame([true, [self::CHALLENGE]], [$server->isScriptComplete(), $mechanism->challenges()]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function capabilityProvider(): array
    {
        return [
            'with SASL-IR'    => ['SASL-IR'],
            'without SASL-IR' => [''],
        ];
    }

    /**
     * RFC 3501 puts a space after "+", but a challenge that follows it directly is read the same.
     */
    #[Test]
    #[DataProvider('continuationProvider')]
    public function readsTheChallengeOfAContinuation(string $continuation, string $challenge): void
    {
        $server = self::server('SASL-IR')
            ->expect('TAG2 AUTHENTICATE X-FAKE ' . self::INITIAL . "\r\n")
            ->reply($continuation)
            ->expect(self::RESPONSE . "\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();
        $mechanism = new FakeMechanism(self::INITIAL, [self::RESPONSE]);

        ScriptedServer::imap($server)->authenticate($mechanism);

        static::assertSame([$challenge], $mechanism->challenges());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function continuationProvider(): array
    {
        return [
            'after a space'   => ['+ ' . self::CHALLENGE . "\r\n", self::CHALLENGE],
            'without a space' => ['+' . self::CHALLENGE . "\r\n", self::CHALLENGE],
            'an empty one'    => ["+\r\n", ''],
            'trailing spaces' => ['+ ' . self::CHALLENGE . "  \r\n", self::CHALLENGE],
        ];
    }

    #[Test]
    public function cancelsAChallengeTheMechanismCannotAnswer(): void
    {
        $server = self::server('SASL-IR')
            ->expect('TAG2 AUTHENTICATE X-FAKE ' . self::INITIAL . "\r\n")
            ->reply('+ ' . self::CHALLENGE . "\r\n")
            ->expect("*\r\n")
            ->reply("TAG2 BAD Cancelled\r\n")
            ->expect("TAG3 NOOP\r\n")
            ->reply("TAG3 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        try {
            $imap->authenticate(new FakeMechanism(self::INITIAL));
            static::fail('The challenge was answered');
        } catch (RuntimeException $e) {
            $imap->noop();
            static::assertSame([FakeMechanism::CANCELLED, true], [$e->getMessage(), $server->isScriptComplete()]);
        }
    }

    #[Test]
    public function reportsARefusalWithTheMechanismsMessage(): void
    {
        $server = self::server('SASL-IR')
            ->expect('TAG2 AUTHENTICATE X-FAKE ' . self::INITIAL . "\r\n")
            ->reply("TAG2 NO [AUTHENTICATIONFAILED] Invalid\r\n")
            ->hangUp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('X-FAKE refused: [AUTHENTICATIONFAILED] Invalid');

        ScriptedServer::imap($server)->authenticate(new FakeMechanism(self::INITIAL));
    }

    #[Test]
    public function refusesAnAcceptanceTheMechanismDoesNotTrust(): void
    {
        $server = self::server('SASL-IR')
            ->expect('TAG2 AUTHENTICATE X-FAKE ' . self::INITIAL . "\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(FakeMechanism::UNTRUSTED);

        ScriptedServer::imap($server)->authenticate(new FakeMechanism(self::INITIAL, trustsAcceptance: false));
    }
}
