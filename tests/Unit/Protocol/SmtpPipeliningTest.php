<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\TestAsset\SmtpServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_slice;
use function count;
use function implode;

/**
 * MAIL and RCPT sent together when the server offers PIPELINING (RFC 2920,
 * contenir/contenir-mail#19). The scripted server refuses a read before every
 * command it expects has been sent, so a passing script shows they went together.
 */
#[CoversClass(Smtp::class)]
#[Group('unit')]
final class SmtpPipeliningTest extends TestCase
{
    private static function server(string ...$capabilities): InMemoryConnection
    {
        $ehlo = [];
        foreach (['mail.example.com', ...$capabilities] as $index => $line) {
            $ehlo[] = (count($capabilities) === $index ? '250 ' : '250-') . $line;
        }

        return (new InMemoryConnection())->reply("220 mail.example.com ESMTP\r\n")
            ->expect("EHLO localhost\r\n")
            ->reply(implode("\r\n", $ehlo) . "\r\n");
    }

    private static function smtp(InMemoryConnection $server): Smtp
    {
        $smtp = new Smtp(new ConnectionConfig('mail.example.com', security: Security::None), connection: $server);
        $smtp->connect();
        $smtp->helo('localhost');

        return $smtp;
    }

    /**
     * @param list<string> $replies The replies to MAIL and each RCPT, in order.
     */
    private static function pipelined(array $replies): InMemoryConnection
    {
        return self::server('PIPELINING')
            ->expect("MAIL FROM:<jo@example.org>\r\n")
            ->expect("RCPT TO:<a@example.org>\r\n")
            ->expect("RCPT TO:<b@example.org>\r\n")
            ->reply(implode("\r\n", $replies) . "\r\n");
    }

    #[Test]
    public function sendsMailAndEveryRecipientBeforeReadingAReply(): void
    {
        $server = self::pipelined(['250 OK', '250 OK', '251 Forwarded'])->hangUp();

        self::smtp($server)->envelope('jo@example.org', ['a@example.org', 'b@example.org']);

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function readsEveryReplyThenResetsWhenARecipientIsRefused(): void
    {
        $server = self::pipelined(['250 OK', '550 5.1.1 No such user a', '550 5.1.1 No such user b'])
            ->expect("RSET\r\n")
            ->reply("250 Reset\r\n")
            ->hangUp();
        $smtp = self::smtp($server);

        try {
            $smtp->envelope('jo@example.org', ['a@example.org', 'b@example.org']);
            static::fail('The refused recipient was not reported');
        } catch (RuntimeException $e) {
            static::assertSame(
                ['5.1.1 No such user a', 550, true],
                [$e->getMessage(), $e->getCode(), $server->isScriptComplete()],
            );
        }
    }

    #[Test]
    public function reportsTheRefusedSenderBeforeTheRecipientsItCausedToFail(): void
    {
        $server = self::pipelined(['553 5.7.1 Sender rejected', '503 5.5.1 Need MAIL', '503 5.5.1 Need MAIL'])
            ->expect("RSET\r\n")
            ->reply("250 Reset\r\n")
            ->hangUp();
        $smtp = self::smtp($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('5.7.1 Sender rejected');

        $smtp->envelope('jo@example.org', ['a@example.org', 'b@example.org']);
    }

    #[Test]
    public function stopsAtAMalformedReplyWithoutReset(): void
    {
        $server = self::pipelined(['250 OK', 'garbage', '250 OK'])->hangUp();
        $smtp   = self::smtp($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server sent a malformed reply line');

        $smtp->envelope('jo@example.org', ['a@example.org', 'b@example.org']);
    }

    #[Test]
    public function waitsForEachReplyWithoutPipelining(): void
    {
        $server = self::server('8BITMIME')
            ->expect("MAIL FROM:<jo@example.org>\r\n")
            ->reply("250 OK\r\n")
            ->expect("RCPT TO:<a@example.org>\r\n")
            ->reply("250 OK\r\n")
            ->expect("RCPT TO:<b@example.org>\r\n")
            ->reply("250 OK\r\n")
            ->hangUp();

        self::smtp($server)->envelope('jo@example.org', ['a@example.org', 'b@example.org']);

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function stopsAtTheFirstRefusalWithoutPipelining(): void
    {
        $server = self::server()
            ->expect("MAIL FROM:<jo@example.org>\r\n")
            ->reply("250 OK\r\n")
            ->expect("RCPT TO:<a@example.org>\r\n")
            ->reply("550 5.1.1 No such user\r\n")
            ->expect("RSET\r\n")
            ->reply("250 Reset\r\n")
            ->hangUp();
        $smtp = self::smtp($server);

        try {
            $smtp->envelope('jo@example.org', ['a@example.org', 'b@example.org']);
            static::fail('The refused recipient was not reported');
        } catch (RuntimeException $e) {
            static::assertSame(['5.1.1 No such user', true], [$e->getMessage(), $server->isScriptComplete()]);
        }
    }

    #[Test]
    public function leavesTheTransactionReadyForData(): void
    {
        $server = new SmtpServer();
        $server->setCapabilities('PIPELINING', 'SMTPUTF8');
        $smtp = new Smtp(new ConnectionConfig('mail.example.com', security: Security::None), connection: $server);
        $smtp->connect();
        $smtp->helo('localhost');
        $smtp->envelope('jö@example.org', ['ä@example.org'], smtpUtf8: true);
        $smtp->rcpt('ö@example.org');
        $smtp->data("Subject: x\r\n\r\nbody");

        static::assertSame(
            [
                ['MAIL FROM:<jö@example.org> SMTPUTF8', 'RCPT TO:<ä@example.org>', 'RCPT TO:<ö@example.org>', 'DATA'],
                300,
            ],
            [
                array_slice($server->sentLines(), offset: 1, length: 4),
                $server->timeoutFor('RCPT TO:<ä@example.org>'),
            ],
        );
    }

    #[Test]
    public function sendsTheMessageStraightAfterTheEnvelope(): void
    {
        $server = new SmtpServer();
        $server->setCapabilities('PIPELINING');
        $smtp = new Smtp(new ConnectionConfig('mail.example.com', security: Security::None), connection: $server);
        $smtp->connect();
        $smtp->helo('localhost');
        $smtp->envelope('jo@example.org', ['a@example.org']);
        $smtp->data("Subject: x\r\n\r\nbody");

        static::assertSame('DATA', $server->sentLines()[3] ?? '');
    }

    #[Test]
    public function checksEveryAddressBeforeSendingAnything(): void
    {
        $server = self::server('PIPELINING')->hangUp();
        $smtp   = self::smtp($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A recipient that is not ASCII needs a transaction started with SMTPUTF8');

        $smtp->envelope('jo@example.org', ['a@example.org', 'ä@example.org']);
    }
}
