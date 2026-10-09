<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Address;
use Contenir\Mail\Header\Date;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Mime\Multipart;
use Contenir\Mail\Mime\MultipartType;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException as ProtocolInvalidArgumentException;
use Contenir\Mail\Protocol\Smtp as SmtpProtocol;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Tests\TestAsset\InjectingHeader;
use Contenir\Mail\Tests\TestAsset\SettableClock;
use Contenir\Mail\Tests\TestAsset\SmtpServer;
use Contenir\Mail\Transport\Envelope;
use Contenir\Mail\Transport\Exception\LogicException;
use Contenir\Mail\Transport\Exception\RuntimeException;
use Contenir\Mail\Transport\HeaderGuard;
use Contenir\Mail\Transport\Smtp;
use Contenir\Mail\Transport\SmtpConfig;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function fopen;
use function fwrite;
use function get_resources;
use function implode;
use function serialize;
use function sprintf;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function unserialize;

#[CoversClass(Smtp::class)]
#[CoversClass(HeaderGuard::class)]
#[Group('unit')]
final class SmtpTest extends TestCase
{
    private const string AUTH_VALUE = 'not-a-real-credential';

    #[Test]
    public function pipelinesTheEnvelopeWhenTheServerOffersIt(): void
    {
        [$transport, , $server] = self::transport();
        $server->setCapabilities('STARTTLS', 'PIPELINING', 'SIZE 1000');
        $transport->send(self::message());

        static::assertCount(3, self::recipients($server));
    }

    #[Test]
    public function sendsMinimalMessageWithSender(): void
    {
        [$transport, , $server] = self::transport();
        $message = self::datedMessage()
            ->setSender('ralph@example.com', 'Ralph Schindler')
            ->setBody('testSendMailWithoutMinimalHeaders')
            ->addTo('test@example.com', 'Example Test');

        $transport->send($message);

        static::assertSame(
            [
                'MAIL FROM:<ralph@example.com> SIZE=156',
                'RCPT TO:<test@example.com>',
                'DATA',
                'Date: Sun, 10 Jun 2012 20:07:24 +0200',
                'Sender: Ralph Schindler <ralph@example.com>',
                'To: Example Test <test@example.com>',
                '',
                'testSendMailWithoutMinimalHeaders',
                '.',
            ],
            self::transaction($server),
        );
    }

    #[Test]
    public function deliversToAnAddressBuiltWithoutStrictChecks(): void
    {
        [$transport, , $server] = self::transport();
        $message = self::datedMessage()
            ->setSender('ralph@example.com')
            ->addTo(new Address('first..last@mail_host.example.com', strict: false));

        $transport->send($message);

        static::assertSame(
            ['RCPT TO:<first..last@mail_host.example.com>', 'To: first..last@mail_host.example.com'],
            [self::transaction($server)[1], self::transaction($server)[5]],
        );
    }

    #[Test]
    public function usesFirstFromAddressWithoutSender(): void
    {
        [$transport, , $server] = self::transport();
        $message = self::datedMessage()->setFrom('ralph@example.com', 'Ralph')->addTo('test@example.com');

        $transport->send($message);

        static::assertStringStartsWith('MAIL FROM:<ralph@example.com>', self::transaction($server)[0]);
    }

    /**
     * Per RFC 5322 section 3.6.
     */
    #[Test]
    public function rejectsMessageWithoutSenderOrFrom(): void
    {
        [$transport] = self::transport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'transport expects either a Sender or at least one From address in the Message; none provided',
        );

        $transport->send((new Message())->addTo('test@example.com'));
    }

    /**
     * Per RFC 5321 section 3.3: RCPT must be sent before DATA.
     */
    #[Test]
    public function rejectsMessageWithoutRecipient(): void
    {
        [$transport] = self::transport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at least one recipient if the message has at least one header or body');

        $transport->send((new Message())->setSender('ralph@example.com'));
    }

    #[Test]
    public function deliversToToCcAndBccRecipientsOnce(): void
    {
        [$transport, , $server] = self::transport();

        $transport->send(self::message()->addCc('test@example.com'));

        static::assertSame(
            ['RCPT TO:<test@example.com>', 'RCPT TO:<matthew@example.com>', 'RCPT TO:<list@example.com>'],
            self::recipients($server),
        );
    }

    #[Test]
    public function usesEnvelopeSender(): void
    {
        [$transport, , $server] = self::transport();
        $transport->setEnvelope(new Envelope(from: 'bounces@example.com'));

        $transport->send(self::message());

        static::assertStringStartsWith('MAIL FROM:<bounces@example.com>', self::transaction($server)[0]);
    }

    #[Test]
    public function usesMessageRecipientsWithEnvelopeSenderOnly(): void
    {
        [$transport, , $server] = self::transport();
        $transport->setEnvelope(new Envelope(from: 'bounces@example.com'));

        $transport->send(self::message());

        static::assertCount(3, self::recipients($server));
    }

    #[Test]
    public function deliversOnlyToEnvelopeRecipients(): void
    {
        [$transport, , $server] = self::transport();
        $transport->setEnvelope(new Envelope(to: ['users@example.com', 'dev@example.com']));

        $transport->send(self::message());

        static::assertSame(['RCPT TO:<users@example.com>', 'RCPT TO:<dev@example.com>'], self::recipients($server));
    }

    #[Test]
    public function usesMessageSenderWithEnvelopeRecipientsOnly(): void
    {
        [$transport, , $server] = self::transport();
        $transport->setEnvelope(new Envelope(to: 'users@example.com'));

        $transport->send(self::message());

        static::assertStringStartsWith('MAIL FROM:<ralph@example.com>', self::transaction($server)[0]);
    }

    #[Test]
    public function keepsEnvelope(): void
    {
        [$transport] = self::transport();
        $envelope = new Envelope(from: 'bounces@example.com');
        $transport->setEnvelope($envelope);

        static::assertSame($envelope, $transport->getEnvelope());
    }

    #[Test]
    public function hasNoEnvelopeByDefault(): void
    {
        static::assertNull(self::transport()[0]->getEnvelope());
    }

    #[DataProvider('messageLineProvider')]
    #[Test]
    public function writesHeadersAndBody(string $expected): void
    {
        [$transport, , $server] = self::transport();

        $transport->send(self::message());

        static::assertContains($expected, self::transaction($server));
    }

    #[Test]
    public function doesNotSendBccHeader(): void
    {
        [$transport, , $server] = self::transport();

        $transport->send(self::mimeMessage());

        static::assertSame(
            [],
            array_values(array_filter(
                self::transaction($server),
                static fn(string $line): bool => str_starts_with($line, 'Bcc:'),
            )),
        );
    }

    #[Test]
    public function keepsBccOnMessageAfterSending(): void
    {
        [$transport] = self::transport();
        $message = self::message();

        $transport->send($message);

        static::assertTrue($message->getHeaders()->has('Bcc'));
    }

    #[DataProvider('mimeLineProvider')]
    #[Test]
    public function writesMimeMessage(string $expected): void
    {
        [$transport, , $server] = self::transport();

        $transport->send(self::mimeMessage());

        static::assertStringContainsString($expected, implode("\r\n", self::transaction($server)));
    }

    #[DataProvider('encodedHeaderProvider')]
    #[Test]
    public function encodesNonAsciiHeadersOnTheWire(string $expected): void
    {
        [$transport, , $server] = self::transport();

        $transport->send(self::message()->setSubject('Grüße aus Köln')->setTo('test@example.com', 'Jösé'));

        static::assertContains($expected, self::transaction($server));
    }

    #[Test]
    public function declaresMessageSize(): void
    {
        [$transport, , $server] = self::transport();
        $message = self::message();

        $transport->send($message);

        $size = strlen($message->getHeaders()->without('Bcc')->toString() . Headers::EOL . $message->getBodyText());
        static::assertSame("MAIL FROM:<ralph@example.com> SIZE={$size}", self::transaction($server)[0]);
    }

    #[Test]
    public function declaresEightBitBody(): void
    {
        [$transport, , $server] = self::transport();

        $transport->send(self::message()->setBody('Grüße'));

        static::assertStringEndsWith(' BODY=8BITMIME', self::transaction($server)[0]);
    }

    #[Test]
    public function declaresEightBitBodyWhenTheByteComesAfterTheFirstChunk(): void
    {
        [$transport, , $server] = self::transport();
        $server->setCapabilities('STARTTLS', 'SIZE 10000000', '8BITMIME');

        $transport->send(self::message()->setBody(str_repeat("0123456789abcdef\r\n", times: 5000) . 'Grüße'));

        static::assertStringEndsWith(' BODY=8BITMIME', self::transaction($server)[0]);
    }

    #[Test]
    public function sendsMessageWithStreamedAttachmentAsToStringWritesIt(): void
    {
        [$transport, , $server] = self::transport();
        $server->setCapabilities('STARTTLS', 'SIZE 10000000', '8BITMIME');
        $content = fopen('php://temp', mode: 'w+b');
        static::assertNotFalse($content);
        fwrite($content, str_repeat("\x00\x80\xFF.abc\r\n", times: 30_000));
        $message = self::message()->setText(".Hi\n")->attach(new Part($content, filename: 'data.bin'));

        $transport->send($message);

        $expected = explode(
            "\r\n",
            str_replace(
                search: "\n.",
                replace: "\n..",
                subject: HeaderGuard::check($message->getHeaders()->without('Bcc'))->toString()
                    . Headers::EOL
                    . $message->getBodyText(),
            ),
        );
        static::assertSame([...$expected, '.'], array_slice(self::transaction($server), offset: 5));
    }

    #[Test]
    public function closesTheStreamItWritesTheMessageTo(): void
    {
        [$transport] = self::transport();
        $streams = count(get_resources('stream'));

        $transport->send(self::message());

        static::assertCount($streams, get_resources('stream'));
    }

    #[Test]
    public function closesTheStreamItWritesTheMessageToWhenSendingFails(): void
    {
        [$transport] = self::transport();
        $streams = count(get_resources('stream'));

        try {
            $transport->send((new Message())->setSender('ralph@example.com'));
        } catch (RuntimeException) {
            static::assertCount($streams, get_resources('stream'));
            return;
        }

        static::fail('A message without recipients was sent');
    }

    #[Test]
    public function declaresSmtpUtf8ForInternationalRecipient(): void
    {
        [$transport, , $server] = self::transport();

        $transport->send(self::message()->addBcc('jösé@example.com'));

        static::assertStringEndsWith(' SMTPUTF8', self::transaction($server)[0]);
    }

    #[Test]
    public function declaresSmtpUtf8ForInternationalEnvelopeSender(): void
    {
        [$transport, , $server] = self::transport();
        $transport->setEnvelope(new Envelope(from: 'jösé@example.com'));

        $transport->send(self::message());

        static::assertStringEndsWith(' SMTPUTF8', self::transaction($server)[0]);
    }

    #[Test]
    public function declaresNoSmtpUtf8ForAsciiAddresses(): void
    {
        [$transport, , $server] = self::transport();

        $transport->send(self::message());

        static::assertStringEndsNotWith(' SMTPUTF8', self::transaction($server)[0]);
    }

    /**
     * A custom header that writes its own line break could add headers the sender never set.
     */
    #[Test]
    public function refusesHeaderWithUnfoldedLineBreakAgainstHeaderInjection(): void
    {
        [$transport] = self::transport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Header "X-Custom" contains a line break that is not folding; not sending it');

        $transport->send(self::message()->addHeader(new InjectingHeader()));
    }

    #[Test]
    public function sendsNothingForHeaderWithUnfoldedLineBreak(): void
    {
        [$transport, , $server] = self::transport();

        try {
            $transport->send(self::message()->addHeader(new InjectingHeader()));
        } catch (RuntimeException) {
            static::assertSame([], self::transaction($server));
            return;
        }

        static::fail('A header with a line break was sent');
    }

    /**
     * A word too long to fold is written as encoded words, so every line fits SMTP's limit.
     */
    #[Test]
    public function sendsHeaderWithOverlongWordWithinLineLimit(): void
    {
        [$transport, , $server] = self::transport();
        $server->setCapabilities('STARTTLS');
        $message = self::message()->addHeader(new GenericHeader('X-Long', str_repeat('0123456789abcdef', times: 64)));

        $transport->send($message);
        $lines = $server->sentLines();

        static::assertSame(
            [['X-Long: =?UTF-8?Q?0123456789abcdef0123456789abcdef0123456789abcdef0123456789?='], []],
            [
                array_values(array_filter($lines, static fn(string $line): bool => str_starts_with($line, 'X-Long:'))),
                array_values(array_filter(
                    $lines,
                    static fn(string $line): bool => strlen($line) > SmtpProtocol::SMTP_LINE_LIMIT,
                )),
            ],
        );
    }

    #[Test]
    public function refusesBodyLineLongerThanSmtpAllows(): void
    {
        [$transport, , $server] = self::transport();
        $server->setCapabilities('STARTTLS');
        $message = self::message()->setBody(str_repeat('0123456789abcdef', times: 64));

        $this->expectException(ProtocolInvalidArgumentException::class);
        $this->expectExceptionMessage('bytes; SMTP allows at most 998');

        $transport->send($message);
    }

    #[Test]
    public function startsSessionOnFirstSend(): void
    {
        [$transport, $connection] = self::transport();

        $transport->send(self::message());

        static::assertTrue($connection->hasSession());
    }

    #[Test]
    public function reusesSessionForNextMessage(): void
    {
        [$transport, , $server] = self::transport();

        $transport->send(self::message());
        $transport->send(self::message());

        static::assertSame(
            ['EHLO localhost', 'STARTTLS', 'EHLO localhost'],
            array_values(array_filter(
                $server->sentLines(),
                static fn(string $line): bool => str_starts_with($line, 'EHLO') || 'STARTTLS' === $line,
            )),
        );
    }

    #[Test]
    public function resetsTransactionBeforeReusingSession(): void
    {
        [$transport, , $server] = self::transport();

        $transport->send(self::message());
        $transport->send(self::message());

        static::assertContains('RSET', $server->sentLines());
    }

    #[Test]
    public function startsNewSessionAfterDisconnect(): void
    {
        [$transport, $connection] = self::transport();
        $transport->send(self::message());
        $connection->disconnect();

        $transport->send(self::message());

        static::assertTrue($connection->hasSession());
    }

    #[Test]
    public function sendsHeloWithConfiguredName(): void
    {
        [$transport, , $server] = self::transport(new SmtpConfig(name: 'client.example.com'));

        $transport->send(self::message());

        static::assertSame('EHLO client.example.com', $server->sentLines()[0]);
    }

    #[Test]
    public function authenticatesWithConnectionAuthenticator(): void
    {
        $transport  = new Smtp();
        $connection = new SmtpProtocol(
            authenticator: new Login('orders', self::AUTH_VALUE),
            connection: new SmtpServer(),
        );
        $transport->setConnection($connection);

        $transport->send(self::message());

        static::assertTrue($connection->isAuthenticated());
    }

    #[Test]
    public function keepsConfig(): void
    {
        $config = new SmtpConfig(host: 'mail.example.com');

        static::assertSame($config, (new Smtp($config))->getConfig());
    }

    #[Test]
    public function readsConfigFromArray(): void
    {
        static::assertSame(
            'mail.example.com',
            (new Smtp(['host' => 'mail.example.com']))->getConfig()->connection->host,
        );
    }

    #[Test]
    public function usesDefaultConfigWithoutSettings(): void
    {
        static::assertEquals(new SmtpConfig(), (new Smtp())->getConfig());
    }

    #[Test]
    public function hasNoConnectionBeforeFirstSend(): void
    {
        static::assertNull((new Smtp())->getConnection());
    }

    #[Test]
    public function appliesCompleteQuitSettingToConnection(): void
    {
        [, $connection] = self::transport(new SmtpConfig(useCompleteQuit: false));

        static::assertFalse($connection->useCompleteQuit());
    }

    #[Test]
    public function sendsQuitByDefault(): void
    {
        [, $connection] = self::transport();

        static::assertTrue($connection->useCompleteQuit());
    }

    #[Test]
    public function sendsNoQuitWithConnectionTimeLimit(): void
    {
        [, $connection] = self::transport(new SmtpConfig(connectionTimeLimit: 60));

        static::assertFalse($connection->useCompleteQuit());
    }

    #[Test]
    public function keepsConnectionWithinTimeLimit(): void
    {
        $clock = new SettableClock();
        [$transport, $connection] = self::transport(new SmtpConfig(connectionTimeLimit: 60), $clock);
        $transport->send(self::message());
        $clock->advance(60);

        static::assertSame($connection, $transport->getConnection());
    }

    #[Test]
    public function dropsConnectionPastTimeLimit(): void
    {
        $clock = new SettableClock();
        [$transport] = self::transport(new SmtpConfig(connectionTimeLimit: 60), $clock);
        $transport->send(self::message());
        $clock->advance(61);

        static::assertNull($transport->getConnection());
    }

    #[Test]
    public function keepsConnectionWithoutTimeLimit(): void
    {
        $clock = new SettableClock();
        [$transport, $connection] = self::transport(new SmtpConfig(), $clock);
        $transport->send(self::message());
        $clock->advance(100_000);

        static::assertSame($connection, $transport->getConnection());
    }

    #[Test]
    public function keepsConnectionWithTimeLimitBeforeConnecting(): void
    {
        $clock = new SettableClock();
        [$transport, $connection] = self::transport(new SmtpConfig(connectionTimeLimit: 60), $clock);
        $clock->advance(61);

        static::assertSame($connection, $transport->getConnection());
    }

    #[Test]
    public function disconnectsOnRequest(): void
    {
        [$transport, , $server] = self::transport();
        $transport->send(self::message());

        $transport->disconnect();

        static::assertFalse($server->isConnected());
    }

    #[Test]
    public function restartsTimeLimitAfterDisconnect(): void
    {
        $clock = new SettableClock();
        [$transport, $connection] = self::transport(new SmtpConfig(connectionTimeLimit: 60), $clock);
        $transport->send(self::message());
        $transport->disconnect();
        $clock->advance(61);

        static::assertSame($connection, $transport->getConnection());
    }

    #[Test]
    public function disconnectsWithoutConnection(): void
    {
        $transport = new Smtp();
        $transport->disconnect();

        static::assertNull($transport->getConnection());
    }

    #[Test]
    public function disconnectsAutomaticallyByDefault(): void
    {
        static::assertTrue((new Smtp())->getAutoDisconnect());
    }

    #[Test]
    public function turnsAutoDisconnectOff(): void
    {
        $transport = new Smtp();
        $transport->setAutoDisconnect(false);

        static::assertFalse($transport->getAutoDisconnect());
    }

    #[Test]
    public function closesConnectionOnDestruction(): void
    {
        [$transport, $connection, $server] = self::transport();
        $transport->send(self::message());

        unset($transport);

        static::assertSame([false, false], [$connection->hasSession(), $server->isConnected()]);
    }

    #[Test]
    public function quitsOnDestructionWithoutAutoDisconnect(): void
    {
        [$transport, $connection, $server] = self::transport();
        $transport->send(self::message());
        $transport->setAutoDisconnect(false);

        unset($transport);

        static::assertSame([false, true], [$connection->hasSession(), $server->isConnected()]);
    }

    #[Test]
    public function closesConnectionToServerThatHasGoneOnDestruction(): void
    {
        [$transport, , $server] = self::transport();
        $transport->send(self::message());
        $server->reply('QUIT', '421 4.4.2 Connection dropped');

        unset($transport);

        static::assertFalse($server->isConnected());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function messageLineProvider(): array
    {
        return [
            'To'                    => ['To: Example Test <test@example.com>'],
            'Subject'               => ['Subject: Testing Contenir\Mail\Transport\Smtp'],
            'Cc'                    => ['Cc: matthew@example.com'],
            'From'                  => ['From: test@example.com,'],
            'X-Foo-Bar'             => ['X-Foo-Bar: Matthew'],
            'Sender'                => ['Sender: Ralph Schindler <ralph@example.com>'],
            'body after blank line' => ['This is only a test.'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mimeLineProvider(): array
    {
        return [
            'MIME-Version'         => ["MIME-Version: 1.0\r\n"],
            'Content-Type'         => ["Content-Type: multipart/mixed; boundary=\"mixed\"\r\n"],
            'preamble after blank' => ["\r\n\r\nThis is a multi-part message in MIME format.\r\n\r\n--mixed\r\n"],
            'alternative part'     => ["--mixed\r\nContent-Type: multipart/alternative; boundary=\"alt\"\r\n"],
            'attachment part'      => ["Content-Disposition: attachment; filename=\"a.txt\"\r\n\r\nYWJj\r\n--mixed--"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function encodedHeaderProvider(): array
    {
        return [
            'Subject'      => ['Subject: =?UTF-8?Q?Gr=C3=BC=C3=9Fe=20aus=20K=C3=B6ln?='],
            'To with name' => ['To: =?UTF-8?Q?J=C3=B6s=C3=A9?= <test@example.com>'],
        ];
    }

    /**
     * A transport whose connection is a scripted server.
     *
     * @return array{Smtp, SmtpProtocol, SmtpServer}
     */
    private static function transport(?SmtpConfig $config = null, ?SettableClock $clock = null): array
    {
        $transport  = new Smtp($config, $clock ?? new SettableClock());
        $server     = new SmtpServer();
        $connection = new SmtpProtocol(connection: $server);
        $transport->setConnection($connection);

        return [$transport, $connection, $server];
    }

    /**
     * The lines sent after the session opened.
     *
     * @return list<string>
     */
    private static function transaction(SmtpServer $server): array
    {
        return array_values(array_filter(
            $server->sentLines(),
            static fn(string $line): bool => ! str_starts_with($line, 'EHLO') && 'STARTTLS' !== $line,
        ));
    }

    /**
     * @return list<string>
     */
    private static function recipients(SmtpServer $server): array
    {
        return array_values(array_filter(
            $server->sentLines(),
            static fn(string $line): bool => str_starts_with($line, 'RCPT'),
        ));
    }

    private static function datedMessage(): Message
    {
        return new Message(new Headers(new Date(new DateTimeImmutable('Sun, 10 Jun 2012 20:07:24 +0200'))));
    }

    private static function message(): Message
    {
        return (new Message())->addTo('test@example.com', 'Example Test')
            ->addCc('matthew@example.com')
            ->addBcc('list@example.com', 'Example List')
            ->addFrom(['test@example.com', 'matthew@example.com' => 'Matthew'])
            ->setSender('ralph@example.com', 'Ralph Schindler')
            ->setSubject('Testing Contenir\Mail\Transport\Smtp')
            ->setBody('This is only a test.')
            ->addHeader(new GenericHeader('X-Foo-Bar', 'Matthew'));
    }

    private static function mimeMessage(): Message
    {
        $alternative = new Multipart(
            MultipartType::Alternative,
            [Part::text('Hello'), Part::html('<p>Hello</p>')],
            'alt',
        );

        return self::message()
            ->setBody(new Multipart(
                MultipartType::Mixed,
                [$alternative, Attachment::fromString('abc', 'a.txt', 'text/plain')],
                'mixed',
            ));
    }

    #[Test]
    public function cannotBeSerializedWithItsCredentials(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(Smtp::class . ' cannot be serialized');

        serialize(new Smtp(['host' => 'mail.example.com']));
    }

    #[Test]
    public function refusesToUnserializeSoACraftedPayloadNeverReachesTheDestructor(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(Smtp::class . ' cannot be unserialized');

        unserialize(sprintf('O:%d:"%s":0:{}', strlen(Smtp::class), Smtp::class));
    }
}
