<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp\Auth;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Sasl\Authentication;
use Contenir\Mail\Protocol\Sasl\Reply;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp;
use Contenir\Mail\Protocol\Smtp\Auth\CallbackChannel;
use Contenir\Mail\Protocol\Smtp\Auth\SaslAuthenticator;
use Contenir\Mail\Tests\TestAsset\Protocol\FakeMechanism;
use Contenir\Mail\Tests\TestAsset\ScriptedChannel;
use Contenir\Mail\Tests\TestAsset\SmtpServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_slice;
use function count;

/**
 * SMTP AUTH (RFC 4954) driven through Sasl\MechanismInterface alone, with a mechanism the client does not know.
 */
#[CoversClass(SaslAuthenticator::class)]
#[CoversClass(Authentication::class)]
#[CoversClass(Reply::class)]
#[Group('unit')]
final class SaslAuthenticatorTest extends TestCase
{
    private const string INITIAL = 'aW5pdGlhbA==';

    private const string CHALLENGE = 'Y2hhbGxlbmdl';

    private const string RESPONSE = 'cmVzcG9uc2U=';

    private const array ACCEPTED = [235, '2.7.0 Accepted'];

    #[Test]
    public function namesTheMechanism(): void
    {
        static::assertSame('X-FAKE', (new SaslAuthenticator(new FakeMechanism()))->mechanism());
    }

    #[Test]
    public function sendsTheInitialResponseAndEachAnswerAsSecrets(): void
    {
        $channel   = new ScriptedChannel('', self::CHALLENGE, self::ACCEPTED);
        $mechanism = new FakeMechanism(self::INITIAL, [self::RESPONSE]);

        (new SaslAuthenticator($mechanism))->authenticate($channel);

        static::assertSame(
            [
                [
                    ['line' => 'AUTH X-FAKE', 'expect' => 334, 'secret' => false],
                    ['line' => self::INITIAL, 'expect' => 334, 'secret' => true],
                    ['line' => self::RESPONSE, 'expect' => 334, 'secret' => true],
                ],
                [self::CHALLENGE],
            ],
            [$channel->steps(), $mechanism->challenges()],
        );
    }

    #[Test]
    public function answersTheFirstChallengeWithoutAnInitialResponse(): void
    {
        $channel   = new ScriptedChannel(self::CHALLENGE, self::ACCEPTED);
        $mechanism = new FakeMechanism(null, [self::RESPONSE]);

        (new SaslAuthenticator($mechanism))->authenticate($channel);

        static::assertSame(
            [[self::CHALLENGE], self::RESPONSE],
            [$mechanism->challenges(), $channel->steps()[1]['line']],
        );
    }

    #[Test]
    public function reportsARefusalWithTheMechanismsMessageAndTheReplyCode(): void
    {
        $channel = new ScriptedChannel('', [535, '5.7.8 Authentication credentials invalid']);

        try {
            (new SaslAuthenticator(new FakeMechanism(self::INITIAL)))->authenticate($channel);
            static::fail('The refusal was not reported');
        } catch (RuntimeException $e) {
            static::assertSame(
                ['X-FAKE refused: 5.7.8 Authentication credentials invalid', 535],
                [$e->getMessage(), $e->getCode()],
            );
        }
    }

    #[Test]
    public function passesOnAFailureWithoutAReplyCode(): void
    {
        $failure = new RuntimeException('Could not read from mail.example.com');
        $channel = new CallbackChannel(static fn(): string => '', static fn(): string => throw $failure);

        try {
            (new SaslAuthenticator(new FakeMechanism(self::INITIAL)))->authenticate($channel);
            static::fail('The failure was not passed on');
        } catch (RuntimeException $e) {
            static::assertSame($failure, $e);
        }
    }

    #[Test]
    public function passesOnTheMechanismRefused(): void
    {
        $channel = new ScriptedChannel([504, '5.5.4 Unrecognized authentication type']);

        try {
            (new SaslAuthenticator(new FakeMechanism(self::INITIAL)))->authenticate($channel);
            static::fail('The refusal was not reported');
        } catch (RuntimeException $e) {
            static::assertSame(
                ['5.5.4 Unrecognized authentication type', 504, 1],
                [$e->getMessage(), $e->getCode(), count($channel->steps())],
            );
        }
    }

    #[Test]
    public function cancelsAChallengeTheMechanismCannotAnswer(): void
    {
        $channel = new ScriptedChannel('', self::CHALLENGE, [501, '5.0.0 Cancelled']);

        try {
            (new SaslAuthenticator(new FakeMechanism(self::INITIAL)))->authenticate($channel);
            static::fail('The challenge was answered');
        } catch (RuntimeException $e) {
            static::assertSame(
                [FakeMechanism::CANCELLED, ['line' => '*', 'expect' => 501, 'secret' => false]],
                [$e->getMessage(), $channel->steps()[2]],
            );
        }
    }

    #[Test]
    public function refusesAnAcceptanceTheMechanismDoesNotTrust(): void
    {
        $channel = new ScriptedChannel('', self::ACCEPTED);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(FakeMechanism::UNTRUSTED);

        (new SaslAuthenticator(new FakeMechanism(self::INITIAL, trustsAcceptance: false)))->authenticate($channel);
    }

    #[Test]
    public function signsInToAnSmtpServer(): void
    {
        $server = new SmtpServer();
        $server->setCapabilities('AUTH X-FAKE');
        $smtp = new Smtp(
            new ConnectionConfig('mail.example.com', security: Security::Tls),
            authenticator: new SaslAuthenticator(new FakeMechanism(self::INITIAL)),
            connection: $server,
        );
        $smtp->connect();
        $smtp->helo('localhost');

        static::assertSame(
            [true, ['AUTH X-FAKE', self::INITIAL]],
            [$smtp->isAuthenticated(), array_slice($server->sentLines(), offset: 1, length: 2)],
        );
    }
}
