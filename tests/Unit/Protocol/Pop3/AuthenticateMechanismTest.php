<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Pop3;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Protocol\Sasl\Authentication;
use Contenir\Mail\Protocol\Sasl\Reply;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\FakeMechanism;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_quote;

/**
 * POP3 AUTH (RFC 5034) driven through Sasl\MechanismInterface alone, with a mechanism the client does not know.
 */
#[CoversClass(Pop3::class)]
#[CoversClass(Authentication::class)]
#[CoversClass(Reply::class)]
#[Group('unit')]
final class AuthenticateMechanismTest extends TestCase
{
    private const string INITIAL = 'aW5pdGlhbA==';

    private const string CHALLENGE = 'Y2hhbGxlbmdl';

    private const string RESPONSE = 'cmVzcG9uc2U=';

    #[Test]
    public function sendsTheInitialResponseAfterTheFirstContinuation(): void
    {
        $server = ScriptedServer::pop3Greeting()
            ->expect("AUTH X-FAKE\r\n")
            ->reply("+ \r\n")
            ->expect(self::INITIAL . "\r\n")
            ->reply('+ ' . self::CHALLENGE . "\r\n")
            ->expect(self::RESPONSE . "\r\n")
            ->reply("+OK Signed in\r\n")
            ->hangUp();
        $mechanism = new FakeMechanism(self::INITIAL, [self::RESPONSE]);

        ScriptedServer::pop3($server)->authenticate($mechanism);

        static::assertSame([true, [self::CHALLENGE]], [$server->isScriptComplete(), $mechanism->challenges()]);
    }

    #[Test]
    public function waitsForTheFirstChallengeWithoutAnInitialResponse(): void
    {
        $server = ScriptedServer::pop3Greeting()
            ->expect("AUTH X-FAKE\r\n")
            ->reply('+ ' . self::CHALLENGE . "\r\n")
            ->expect(self::RESPONSE . "\r\n")
            ->reply("+OK Signed in\r\n")
            ->hangUp();
        $mechanism = new FakeMechanism(null, [self::RESPONSE]);

        ScriptedServer::pop3($server)->authenticate($mechanism);

        static::assertSame([true, [self::CHALLENGE]], [$server->isScriptComplete(), $mechanism->challenges()]);
    }

    #[Test]
    public function sendsAnEmptyInitialResponseAsAnEmptyLine(): void
    {
        $server = ScriptedServer::pop3Greeting()
            ->expect("AUTH X-FAKE\r\n")
            ->reply("+ \r\n")
            ->expect("\r\n")
            ->reply("+OK Signed in\r\n")
            ->hangUp();

        ScriptedServer::pop3($server)->authenticate(new FakeMechanism(''));

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    #[DataProvider('mechanismRefusalProvider')]
    public function reportsTheMechanismRefusedBeforeTheInitialResponse(string $reply, string $message): void
    {
        $pop3 = ScriptedServer::pop3(
            ScriptedServer::pop3Greeting()->expect("AUTH X-FAKE\r\n")->reply($reply)->hangUp(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, delimiter: '/') . '$/D');

        $pop3->authenticate(new FakeMechanism(self::INITIAL));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function mechanismRefusalProvider(): array
    {
        return [
            'a refusal'        => [
                "-ERR [AUTH] Unknown mechanism\r\n",
                'last request failed: [AUTH] Unknown mechanism',
            ],
            'early acceptance' => ["+OK\r\n", 'last request failed'],
        ];
    }

    #[Test]
    public function cancelsAChallengeTheMechanismCannotAnswer(): void
    {
        $server = ScriptedServer::pop3Greeting()
            ->expect("AUTH X-FAKE\r\n")
            ->reply('+ ' . self::CHALLENGE . "\r\n")
            ->expect("*\r\n")
            ->reply("-ERR Cancelled\r\n")
            ->expect("NOOP\r\n")
            ->reply("+OK\r\n")
            ->hangUp();
        $pop3 = ScriptedServer::pop3($server);

        try {
            $pop3->authenticate(new FakeMechanism());
            static::fail('The challenge was answered');
        } catch (RuntimeException $e) {
            $pop3->noop();
            static::assertSame([FakeMechanism::CANCELLED, true], [$e->getMessage(), $server->isScriptComplete()]);
        }
    }

    #[Test]
    #[DataProvider('refusalProvider')]
    public function reportsARefusalWithTheMechanismsMessage(string $reply, string $message): void
    {
        $pop3 = ScriptedServer::pop3(
            ScriptedServer::pop3Greeting()
                ->expect("AUTH X-FAKE\r\n")
                ->reply('+ ' . self::CHALLENGE . "\r\n")
                ->expect(self::RESPONSE . "\r\n")
                ->reply($reply)
                ->hangUp(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, delimiter: '/') . '$/D');

        $pop3->authenticate(new FakeMechanism(null, [self::RESPONSE]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'with a reason'      => ["-ERR [AUTH] Invalid\r\n", 'X-FAKE refused: [AUTH] Invalid'],
            'control characters' => ["-ERR a\x1B[31mb\r\n", 'X-FAKE refused: a [31mb'],
        ];
    }

    #[Test]
    public function refusesAnAcceptanceTheMechanismDoesNotTrust(): void
    {
        $pop3 = ScriptedServer::pop3(
            ScriptedServer::pop3Greeting()
                ->expect("AUTH X-FAKE\r\n")
                ->reply("+ \r\n")
                ->expect(self::INITIAL . "\r\n")
                ->reply("+OK\r\n")
                ->hangUp(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(FakeMechanism::UNTRUSTED);

        $pop3->authenticate(new FakeMechanism(self::INITIAL, trustsAcceptance: false));
    }
}
