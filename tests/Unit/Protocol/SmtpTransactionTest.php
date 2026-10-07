<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp;
use Contenir\Mail\Protocol\Smtp\Auth\Plain;
use Contenir\Mail\Tests\Unit\TestAsset\SmtpServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_filter;
use function array_slice;
use function array_values;
use function base64_encode;
use function str_repeat;

/**
 * Mail transactions and the other commands of an open session.
 */
#[CoversClass(Smtp::class)]
#[Group('unit')]
final class SmtpTransactionTest extends TestCase
{
    #[Test]
    public function sendsEnvelopeAndMessage(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $smtp->mail('sender@example.com');
        $smtp->rcpt('recipient@example.com');
        $smtp->data("Subject: Hi\r\n\r\nHello");

        static::assertSame(
            [
                'MAIL FROM:<sender@example.com>',
                'RCPT TO:<recipient@example.com>',
                'DATA',
                'Subject: Hi',
                '',
                'Hello',
                '.',
            ],
            self::transaction($server),
        );
    }

    #[Test]
    public function sendsNullReversePathForEmptySender(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $smtp->mail('');

        static::assertSame(['MAIL FROM:<>'], self::transaction($server));
    }

    #[Test]
    public function acceptsQuotedLocalPartWithSpaces(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $smtp->mail('"john doe"@example.com');

        static::assertSame(['MAIL FROM:<"john doe"@example.com>'], self::transaction($server));
    }

    #[Test]
    public function refusesMailWithoutSession(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A valid session has not been started');

        self::smtp()->mail('sender@example.com');
    }

    /**
     * An envelope address must not be able to close the path, add parameters or start a command.
     */
    #[DataProvider('unsafeAddressProvider')]
    #[Test]
    public function refusesUnsafeSenderAgainstCommandInjection(string $address): void
    {
        $smtp = self::session();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'An envelope address must not contain control characters, angle brackets or unquoted spaces',
        );

        $smtp->mail($address);
    }

    #[DataProvider('unsafeAddressProvider')]
    #[Test]
    public function refusesUnsafeRecipientAgainstCommandInjection(string $address): void
    {
        $smtp = self::session();
        $smtp->mail('sender@example.com');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'An envelope address must not contain control characters, angle brackets or unquoted spaces',
        );

        $smtp->rcpt($address);
    }

    #[Test]
    public function sendsNothingForUnsafeRecipient(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $smtp->mail('sender@example.com');

        try {
            $smtp->rcpt("x@example.com>\r\nRCPT TO:<victim@example.com");
        } catch (InvalidArgumentException) {
            static::assertSame(['MAIL FROM:<sender@example.com>'], self::transaction($server));
            return;
        }

        static::fail('An unsafe recipient was sent');
    }

    /**
     * @param array{int|null, bool, bool} $parameters
     */
    #[DataProvider('mailParameterProvider')]
    #[Test]
    public function declaresMailParametersServerSupports(array $parameters, string $expected): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $smtp->mail('sender@example.com', ...$parameters);

        static::assertSame([$expected], self::transaction($server));
    }

    #[DataProvider('unsupportedParameterProvider')]
    #[Test]
    public function leavesOutParametersServerDoesNotSupport(string $capabilities): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server, $capabilities);
        $smtp->mail('sender@example.com', 10, eightBit: true);

        static::assertSame(['MAIL FROM:<sender@example.com>'], self::transaction($server));
    }

    #[Test]
    public function refusesMessageLargerThanServerAccepts(): void
    {
        $smtp = self::session();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The message is 1001 bytes; the server accepts at most 1000');

        $smtp->mail('sender@example.com', 1001);
    }

    #[DataProvider('unlimitedSizeProvider')]
    #[Test]
    public function acceptsMessageOfAnySizeWithoutLimit(string $capability, int $size): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server, $capability);
        $smtp->mail('sender@example.com', $size);

        static::assertSame(["MAIL FROM:<sender@example.com> SIZE={$size}"], self::transaction($server));
    }

    #[Test]
    public function declaresSmtpUtf8ForInternationalSender(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $smtp->mail('jösé@example.com');

        static::assertSame(['MAIL FROM:<jösé@example.com> SMTPUTF8'], self::transaction($server));
    }

    /**
     * Without SMTPUTF8 a server may mangle or misroute an address that is not ASCII.
     */
    #[Test]
    public function refusesInternationalSenderWithoutSmtpUtf8(): void
    {
        $smtp = self::session(null, '8BITMIME');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server does not offer SMTPUTF8');

        $smtp->mail('jösé@example.com');
    }

    #[Test]
    public function refusesInternationalRecipientOutsideSmtpUtf8Transaction(): void
    {
        $smtp = self::session();
        $smtp->mail('sender@example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A recipient that is not ASCII needs a transaction started with SMTPUTF8');

        $smtp->rcpt('jösé@example.com');
    }

    #[Test]
    public function acceptsInternationalRecipientInSmtpUtf8Transaction(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $smtp->mail('sender@example.com', smtpUtf8: true);
        $smtp->rcpt('jösé@example.com');

        static::assertSame('RCPT TO:<jösé@example.com>', self::transaction($server)[1]);
    }

    #[Test]
    public function refusesRecipientWithoutSender(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No sender reverse path has been supplied');

        self::session()->rcpt('recipient@example.com');
    }

    #[Test]
    public function acceptsForwardingReplyToRecipient(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $server->reply('RCPT', '251 2.1.5 User not local; will forward');
        $smtp->mail('sender@example.com');
        $smtp->rcpt('recipient@example.com');
        $smtp->data('Hello');

        static::assertSame('.', self::transaction($server)[4]);
    }

    #[Test]
    public function reportsRefusedRecipientWithCode(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $server->reply('RCPT', '550 5.1.1 Mailbox "nosuchuser" does not exist');
        $smtp->mail('sender@example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('5.1.1 Mailbox "nosuchuser" does not exist');
        $this->expectExceptionCode(550);

        $smtp->rcpt('nosuchuser@example.com');
    }

    #[Test]
    public function joinsLinesOfMultilineError(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $server->reply('MAIL', '550-5.7.1 Sender rejected;', '550 5.7.1 see policy');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('5.7.1 Sender rejected; 5.7.1 see policy');

        $smtp->mail('sender@example.com');
    }

    #[Test]
    public function refusesDataWithoutRecipient(): void
    {
        $smtp = self::session();
        $smtp->mail('sender@example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No recipient forward path has been supplied');

        $smtp->data('Hello');
    }

    #[Test]
    public function endsTransactionAfterData(): void
    {
        $smtp = self::open();
        $smtp->data('Hello');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No sender reverse path has been supplied');

        $smtp->rcpt('recipient@example.com');
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('lineEndingProvider')]
    #[Test]
    public function normalisesLineEndingsToCrlf(string $data, array $expected): void
    {
        $server = new SmtpServer();
        $smtp   = self::open($server);
        $smtp->data($data);

        static::assertSame([...$expected, '.'], self::message($server));
    }

    /**
     * SMTP smuggling: a lone "." between bare CR or LF line endings must not end the message,
     * or the rest would be read as new commands by servers that accept them.
     */
    #[DataProvider('smugglingProvider')]
    #[Test]
    public function sendsNoEarlyEndOfDataAgainstSmtpSmuggling(string $data): void
    {
        $server = new SmtpServer();
        $smtp   = self::open($server);
        $smtp->data($data);

        static::assertSame(
            ['.'],
            array_values(array_filter(self::message($server), static fn(string $line): bool => '.' === $line)),
        );
    }

    #[Test]
    public function doublesLeadingDot(): void
    {
        $server = new SmtpServer();
        $smtp   = self::open($server);
        $smtp->data(".hidden\r\n..twice");

        static::assertSame(['..hidden', '...twice', '.'], self::message($server));
    }

    /**
     * Folding a body line would change the content and break DKIM signatures.
     */
    #[Test]
    public function refusesLineLongerThanLimitRatherThanAlteringIt(): void
    {
        $smtp = self::open();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Line 2 of the message is 999 bytes; SMTP allows at most 998. Encode the content '
                . '(quoted-printable or base64) instead of sending it as is.',
        );

        $smtp->data("Subject: x\r\n" . str_repeat('a', Smtp::SMTP_LINE_LIMIT + 1));
    }

    #[Test]
    public function sendsNothingForLineLongerThanLimit(): void
    {
        $server = new SmtpServer();
        $smtp   = self::open($server);

        try {
            $smtp->data(str_repeat('a', Smtp::SMTP_LINE_LIMIT + 1));
        } catch (InvalidArgumentException) {
            static::assertSame([], self::message($server));
            return;
        }

        static::fail('An over-long line was sent');
    }

    #[Test]
    public function sendsLineOfExactlyLimit(): void
    {
        $server = new SmtpServer();
        $smtp   = self::open($server);
        $smtp->data(str_repeat('a', Smtp::SMTP_LINE_LIMIT));

        static::assertSame([str_repeat('a', Smtp::SMTP_LINE_LIMIT), '.'], self::message($server));
    }

    #[Test]
    public function refusesSecondDataWithoutNewTransaction(): void
    {
        $smtp = self::open();
        $smtp->data('Hello');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No recipient forward path has been supplied');

        $smtp->data('Hello again');
    }

    #[Test]
    public function refusesDataAfterReset(): void
    {
        $smtp = self::open();
        $smtp->rset();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No recipient forward path has been supplied');

        $smtp->data('Hello');
    }

    #[Test]
    public function refusesDataAfterQuit(): void
    {
        $smtp = self::open();
        $smtp->quit();
        $smtp->connect();
        $smtp->helo('localhost');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No recipient forward path has been supplied');

        $smtp->data('Hello');
    }

    /**
     * @param callable(Smtp): void $command
     */
    #[DataProvider('refusedCommandProvider')]
    #[Test]
    public function reportsRefusedCommand(string $prefix, callable $command): void
    {
        $server = new SmtpServer();
        $smtp   = self::open($server);
        $server->reply($prefix, '554 5.0.0 Refused');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('5.0.0 Refused');
        $this->expectExceptionCode(554);

        $command($smtp);
    }

    /**
     * @param callable(Smtp): void $command
     */
    #[DataProvider('acceptedReplyProvider')]
    #[Test]
    public function acceptsAlternativeSuccessReply(string $prefix, string $reply, callable $command): void
    {
        $server = new SmtpServer();
        $smtp   = self::open($server);
        $server->reply($prefix, $reply);
        $command($smtp);

        static::assertSame([$reply], $smtp->getResponse());
    }

    /**
     * RFC 5321 section 4.5.3.2 gives each reply its own time limit.
     *
     * @param callable(Smtp): void $command
     */
    #[DataProvider('timeoutProvider')]
    #[Test]
    public function waitsForReplyAsLongAsRfc5321Allows(callable $command, string $request, ?int $expected): void
    {
        $server = new SmtpServer();
        $smtp   = new Smtp(
            new ConnectionConfig('mail.example.com'),
            config: [],
            authenticator: new Plain('orders', 'secret'),
            connection: $server,
        );
        $smtp->connect();
        $smtp->helo('localhost');
        $command($smtp);

        static::assertSame($expected, $server->timeoutFor($request));
    }

    /**
     * A hostile or broken server must not be able to pass off a malformed line as a reply.
     */
    #[DataProvider('malformedReplyProvider')]
    #[Test]
    public function refusesMalformedReply(string $line): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $server->reply('NOOP', $line);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server sent a malformed reply line');

        $smtp->noop();
    }

    #[Test]
    public function acceptsReplyWithoutText(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $server->reply('NOOP', '250');
        $smtp->noop();

        static::assertSame(['250'], $smtp->getResponse());
    }

    #[Test]
    public function resetsTransaction(): void
    {
        $smtp = self::session();
        $smtp->mail('sender@example.com');
        $smtp->rset();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No sender reverse path has been supplied');

        $smtp->rcpt('recipient@example.com');
    }

    #[Test]
    public function sendsNoop(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $smtp->noop();

        static::assertSame(['NOOP'], self::transaction($server));
    }

    #[Test]
    public function sendsVrfy(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $smtp->vrfy('postmaster');

        static::assertSame(['VRFY postmaster'], self::transaction($server));
    }

    #[Test]
    public function refusesLineBreakInCommandAgainstInjection(): void
    {
        $smtp = self::session();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An SMTP command must not contain CR, LF or NUL');

        $smtp->vrfy("postmaster\r\nRSET");
    }

    #[DataProvider('commandBreakProvider')]
    #[Test]
    public function sendsNothingForCommandWithBreak(string $user): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);

        try {
            $smtp->vrfy($user);
        } catch (InvalidArgumentException) {
            static::assertSame([], self::transaction($server));
            return;
        }

        static::fail('A command with a line break was sent');
    }

    #[Test]
    public function readsReplyOfMaximumLength(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $server->reply('NOOP', ...[
            ...array_fill(
                start_index: 0,
                count: Smtp::MAX_REPLY_LINES - 1,
                value: '250-OK',
            ),
            '250 OK',
        ]);
        $smtp->noop();

        static::assertCount(Smtp::MAX_REPLY_LINES, $smtp->getResponse());
    }

    /**
     * A hostile server must not be able to keep the client reading one endless reply.
     */
    #[Test]
    public function refusesReplyLongerThanLimit(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $server->reply('NOOP', ...[
            ...array_fill(
                start_index: 0,
                count: Smtp::MAX_REPLY_LINES,
                value: '250-OK',
            ),
            '250 OK',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server reply is longer than 100 lines');

        $smtp->noop();
    }

    #[Test]
    public function readsEveryLineOfMultilineReply(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $server->reply('NOOP', '250-first', '250 last');
        $smtp->noop();

        static::assertSame(['250-first', '250 last'], $smtp->getResponse());
    }

    #[Test]
    public function sendsQuitAndEndsSession(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $smtp->quit();

        static::assertSame([false, ['QUIT']], [$smtp->hasSession(), self::transaction($server)]);
    }

    #[Test]
    public function sendsNoQuitWhenTurnedOff(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $smtp->setUseCompleteQuit(false);
        $smtp->quit();

        static::assertSame([false, []], [$smtp->hasSession(), self::transaction($server)]);
    }

    #[Test]
    public function sendsNoQuitWithoutSession(): void
    {
        $server = new SmtpServer();
        $smtp   = self::smtp($server);
        $smtp->connect();
        $smtp->quit();

        static::assertSame([], $server->sentLines());
    }

    #[Test]
    public function endsTransactionOnQuit(): void
    {
        $smtp = self::open();
        $smtp->quit();
        $smtp->connect();
        $smtp->helo('localhost');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No sender reverse path has been supplied');

        $smtp->rcpt('recipient@example.com');
    }

    #[Test]
    public function disconnectsAfterQuit(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $smtp->disconnect();

        static::assertSame([false, ['QUIT']], [$server->isConnected(), self::transaction($server)]);
    }

    #[Test]
    public function disconnectsFromServerThatHasGone(): void
    {
        $server = new SmtpServer();
        $smtp   = self::session($server);
        $server->reply('QUIT', '421 4.4.2 Connection dropped');
        $smtp->disconnect();

        static::assertFalse($server->isConnected());
    }

    /**
     * @return array<string, array{string, callable(Smtp): void}>
     */
    public static function refusedCommandProvider(): array
    {
        return [
            'end of data' => ['.', static fn(Smtp $smtp) => $smtp->data('Hello')],
            'DATA'        => ['DATA', static fn(Smtp $smtp) => $smtp->data('Hello')],
            'RSET'        => ['RSET', static fn(Smtp $smtp) => $smtp->rset()],
            'NOOP'        => ['NOOP', static fn(Smtp $smtp) => $smtp->noop()],
            'VRFY'        => ['VRFY', static fn(Smtp $smtp) => $smtp->vrfy('postmaster')],
            'QUIT'        => ['QUIT', static fn(Smtp $smtp) => $smtp->quit()],
        ];
    }

    /**
     * @return array<string, array{string, string, callable(Smtp): void}>
     */
    public static function acceptedReplyProvider(): array
    {
        return [
            'RSET 220' => ['RSET', '220 2.0.0 Reset', static fn(Smtp $smtp) => $smtp->rset()],
            'VRFY 251' => ['VRFY', '251 2.1.5 Forwarded', static fn(Smtp $smtp) => $smtp->vrfy('postmaster')],
            'VRFY 252' => [
                'VRFY',
                '252 2.1.5 Cannot verify',
                static fn(Smtp $smtp) => $smtp->vrfy('postmaster'),
            ],
        ];
    }

    /**
     * @return array<string, array{callable(Smtp): void, string, int|null}>
     */
    public static function timeoutProvider(): array
    {
        $none = static function (Smtp $smtp): void {};
        $send = static function (Smtp $smtp): void {
            $smtp->mail('sender@example.com');
            $smtp->rcpt('recipient@example.com');
            $smtp->data('Hello');
        };

        return [
            'greeting'      => [$none, '', 300],
            'EHLO'          => [$none, 'EHLO localhost', 300],
            'STARTTLS'      => [$none, 'STARTTLS', 180],
            'AUTH'          => [$none, 'AUTH PLAIN', 300],
            'AUTH response' => [$none, base64_encode("\0orders\0secret"), 300],
            'MAIL'          => [$send, 'MAIL FROM:<sender@example.com>', 300],
            'RCPT'          => [$send, 'RCPT TO:<recipient@example.com>', 300],
            'DATA'          => [$send, 'DATA', 120],
            'end of data'   => [$send, '.', 600],
            'RSET'          => [static fn(Smtp $smtp) => $smtp->rset(), 'RSET', null],
            'NOOP'          => [static fn(Smtp $smtp) => $smtp->noop(), 'NOOP', 300],
            'VRFY'          => [static fn(Smtp $smtp) => $smtp->vrfy('postmaster'), 'VRFY postmaster', 300],
            'QUIT'          => [static fn(Smtp $smtp) => $smtp->quit(), 'QUIT', 300],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedReplyProvider(): array
    {
        return [
            'two digits'        => ['25 OK'],
            'four digits'       => ['2500 OK'],
            'text before code'  => ['x250 OK'],
            'letter after code' => ['250x OK'],
            'empty'             => [''],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeAddressProvider(): array
    {
        return [
            'CRLF and a command' => ["x@example.com>\r\nRCPT TO:<victim@example.com"],
            'bare LF'            => ["x@example.com\nDATA"],
            'NUL'                => ["x@example.com\0"],
            'closing bracket'    => ['x@example.com> SIZE=1'],
            'opening bracket'    => ['<x@example.com'],
            'unquoted space'     => ['x@example.com NOTIFY=NEVER'],
            'tab'                => ["x@example.com\tNOTIFY=NEVER"],
            'space after quotes' => ['"x"@example.com BODY=8BITMIME'],
            'DEL'                => ["x@example.com\x7F"],
        ];
    }

    /**
     * @return array<string, array{array{0?: int|null, 1?: bool, 2?: bool}, string}>
     */
    public static function mailParameterProvider(): array
    {
        return [
            'no size'        => [[], 'MAIL FROM:<sender@example.com>'],
            'size'           => [[10], 'MAIL FROM:<sender@example.com> SIZE=10'],
            'size at limit'  => [[1000], 'MAIL FROM:<sender@example.com> SIZE=1000'],
            '8-bit body'     => [[null, false, true], 'MAIL FROM:<sender@example.com> BODY=8BITMIME'],
            'SMTPUTF8'       => [[null, true], 'MAIL FROM:<sender@example.com> SMTPUTF8'],
            'all parameters' => [[10, true, true], 'MAIL FROM:<sender@example.com> SIZE=10 BODY=8BITMIME SMTPUTF8'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupportedParameterProvider(): array
    {
        return [
            'neither SIZE nor 8BITMIME' => ['PIPELINING'],
        ];
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function unlimitedSizeProvider(): array
    {
        return [
            'SIZE without a limit' => ['SIZE', 5000],
            'SIZE 0'               => ['SIZE 0', 5000],
            'SIZE not a number'    => ['SIZE many', 5000],
            'SIZE not decimal'     => ['SIZE 0x10', 5],
        ];
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function lineEndingProvider(): array
    {
        return [
            'CRLF'                => ["a\r\nb", ['a', 'b']],
            'bare LF'             => ["a\nb", ['a', 'b']],
            'bare CR'             => ["a\rb", ['a', 'b']],
            'mixed'               => ["a\rb\nc\r\nd", ['a', 'b', 'c', 'd']],
            'trailing CRLF'       => ["a\r\n", ['a']],
            'trailing bare CR'    => ["a\r", ['a']],
            'blank line kept'     => ["a\r\n\r\nb", ['a', '', 'b']],
            'two trailing breaks' => ["a\n\n", ['a', '']],
            'empty'               => ['', []],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function smugglingProvider(): array
    {
        return [
            'CR dot CR'     => ["Hi\r.\rMAIL FROM:<x@example.com>"],
            'LF dot LF'     => ["Hi\n.\nMAIL FROM:<x@example.com>"],
            'CRLF dot LF'   => ["Hi\r\n.\nMAIL FROM:<x@example.com>"],
            'LF dot CRLF'   => ["Hi\n.\r\nMAIL FROM:<x@example.com>"],
            'CR dot CRLF'   => ["Hi\r.\r\nMAIL FROM:<x@example.com>"],
            'CRLF dot CRLF' => ["Hi\r\n.\r\nMAIL FROM:<x@example.com>"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function commandBreakProvider(): array
    {
        return [
            'CRLF' => ["postmaster\r\nRSET"],
            'CR'   => ["postmaster\rRSET"],
            'LF'   => ["postmaster\nRSET"],
            'NUL'  => ["postmaster\0"],
        ];
    }

    private static function smtp(?SmtpServer $server = null): Smtp
    {
        return new Smtp(
            new ConnectionConfig('mail.example.com', security: Security::None),
            connection: $server ?? new SmtpServer(),
        );
    }

    private static function session(?SmtpServer $server = null, string ...$capabilities): Smtp
    {
        $server ??= new SmtpServer();
        if ([] !== $capabilities) {
            $server->setCapabilities(...$capabilities);
        }

        $smtp = self::smtp($server);
        $smtp->connect();
        $smtp->helo('localhost');

        return $smtp;
    }

    /**
     * A session with MAIL and RCPT accepted.
     */
    private static function open(?SmtpServer $server = null): Smtp
    {
        $smtp = self::session($server);
        $smtp->mail('sender@example.com');
        $smtp->rcpt('recipient@example.com');

        return $smtp;
    }

    /**
     * The lines sent after EHLO.
     *
     * @return list<string>
     */
    private static function transaction(SmtpServer $server): array
    {
        return array_slice($server->sentLines(), offset: 1);
    }

    /**
     * The lines sent after DATA.
     *
     * @return list<string>
     */
    private static function message(SmtpServer $server): array
    {
        return array_slice($server->sentLines(), offset: 4);
    }
}
