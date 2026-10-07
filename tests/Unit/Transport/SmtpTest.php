<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Header\Date;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use Contenir\Mail\Protocol\Smtp as SmtpProtocol;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Protocol\SmtpPluginManager;
use Contenir\Mail\Tests\Unit\TestAsset\SmtpProtocolSpy;
use Contenir\Mail\Transport\Envelope;
use Contenir\Mail\Transport\Exception;
use Contenir\Mail\Transport\Smtp;
use Contenir\Mail\Transport\SmtpOptions;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_filter;
use function explode;
use function str_repeat;
use function strlen;
use function substr;
use function time;

#[CoversClass(Smtp::class)]
#[Group('unit')]
final class SmtpTest extends TestCase
{
    private const string TEST_AUTH_VALUE = 'not-real';

    private ?Smtp $transport;

    private SmtpProtocolSpy $connection;

    protected function setUp(): void
    {
        $this->transport  = new Smtp();
        $this->connection = new SmtpProtocolSpy();
        $this->transport->setConnection($this->connection);
    }

    /**
     * Per RFC 2822 3.6
     */
    #[Test]
    public function rejectsMessageWithoutSenderOrFrom(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage(
            'transport expects either a Sender or at least one From address in the Message; none provided',
        );

        $this->getTransport()->send(new Message());
    }

    /**
     * Per RFC 2821 3.3 (page 18): RCPT must be called before DATA.
     */
    #[Test]
    public function rejectsMessageWithoutRecipient(): void
    {
        $message = (new Message())->setSender('ralph@example.com', 'Ralph Schindler');

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('at least one recipient if the message has at least one header or body');

        $this->getTransport()->send($message);
    }

    #[DataProvider('envelopeFromLogProvider')]
    #[Test]
    public function usesEnvelopeFromAndMessageRecipients(string $expected): void
    {
        $this->getTransport()->setEnvelope(new Envelope(['from' => 'mailer@example.com']));
        $this->getTransport()->send($this->makeMessage());

        static::assertStringContainsString($expected, $this->connection->getLog());
    }

    #[DataProvider('envelopeToLogProvider')]
    #[Test]
    public function usesEnvelopeToAndMessageSender(string $expected): void
    {
        $this->getTransport()->setEnvelope(new Envelope(['to' => 'users@example.com']));
        $this->getTransport()->send($this->makeMessage());

        static::assertStringContainsString($expected, $this->connection->getLog());
    }

    #[Test]
    public function deliversOnlyToEnvelopeRecipients(): void
    {
        $to = ['users@example.com', 'dev@example.com'];
        $this->getTransport()->setEnvelope(new Envelope(['from' => 'mailer@example.com', 'to' => $to]));
        $this->getTransport()->send($this->makeMessage());

        static::assertSame($to, $this->connection->getRecipients());
    }

    #[DataProvider('envelopeLogProvider')]
    #[Test]
    public function usesEnvelopeFromAndTo(string $expected): void
    {
        $this->getTransport()->setEnvelope(new Envelope([
            'from' => 'mailer@example.com',
            'to'   => ['users@example.com', 'dev@example.com'],
        ]));
        $this->getTransport()->send($this->makeMessage());

        static::assertStringContainsString($expected, $this->connection->getLog());
    }

    #[Test]
    public function sendsMinimalMessageWithSender(): void
    {
        $message = $this->makeDatedMessage()
            ->setSender('ralph@example.com', 'Ralph Schindler')
            ->setBody('testSendMailWithoutMinimalHeaders')
            ->addTo('test@example.com', 'Example Test');

        $this->getTransport()->send($message);

        static::assertStringContainsString(
            "Date: Sun, 10 Jun 2012 20:07:24 +0200\r\n"
                . "Sender: Ralph Schindler <ralph@example.com>\r\n"
                . "To: Example Test <test@example.com>\r\n"
                . "\r\n"
                . 'testSendMailWithoutMinimalHeaders',
            $this->connection->getLog(),
        );
    }

    #[Test]
    public function sendsMinimalMessageWithoutSender(): void
    {
        $message = $this->makeDatedMessage()
            ->setFrom('ralph@example.com', 'Ralph Schindler')
            ->setBody('testSendMinimalMailWithoutSender')
            ->addTo('test@example.com', 'Example Test');

        $this->getTransport()->send($message);

        static::assertStringContainsString(
            "Date: Sun, 10 Jun 2012 20:07:24 +0200\r\n"
                . "From: Ralph Schindler <ralph@example.com>\r\n"
                . "To: Example Test <test@example.com>\r\n"
                . "\r\n"
                . 'testSendMinimalMailWithoutSender',
            $this->connection->getLog(),
        );
    }

    #[Test]
    public function deliversToToCcAndBccRecipients(): void
    {
        $this->getTransport()->send($this->makeMessage());

        static::assertSame(
            ['test@example.com', 'matthew@example.com', 'list@example.com'],
            $this->connection->getRecipients(),
        );
    }

    #[Test]
    public function deliversToEachRecipientOnce(): void
    {
        $message = $this->makeMessage()->addCc('test@example.com');

        $this->getTransport()->send($message);

        static::assertSame(
            ['test@example.com', 'matthew@example.com', 'list@example.com'],
            $this->connection->getRecipients(),
        );
    }

    #[DataProvider('messageLogProvider')]
    #[Test]
    public function writesMessageToConnection(string $expected): void
    {
        $this->getTransport()->send($this->makeMessage());

        static::assertStringContainsString($expected, $this->connection->getLog());
    }

    #[Test]
    public function doesNotSendBccHeader(): void
    {
        $this->getTransport()->send($this->makeMessage());

        static::assertStringNotContainsString('Bcc:', $this->connection->getLog());
    }

    #[Test]
    public function keepsBccOnMessageAfterSending(): void
    {
        $message = $this->makeMessage();

        $this->getTransport()->send($message);

        static::assertTrue($message->getHeaders()->has('Bcc'));
    }

    #[DataProvider('encodedHeaderProvider')]
    #[Test]
    public function encodesNonAsciiHeadersOnTheWire(string $expected): void
    {
        $message = $this->makeMessage()
            ->setSubject('Grüße aus Köln')
            ->setTo('test@example.com', 'Jösé');

        $this->getTransport()->send($message);

        static::assertStringContainsString($expected, $this->connection->getLog());
    }

    /**
     * Fold long lines during SMTP communication, following RFC 5322 section 2.2.3.
     *
     * @see https://github.com/laminas/laminas-mail/pull/140
     */
    #[Test]
    public function foldsHeaderLinesLongerThanLineLimit(): void
    {
        $this->getTransport()->send($this->makeMessageWithLongHeaders());

        static::assertSame(
            [],
            array_filter(
                explode("\r\n", $this->connection->getLog()),
                static fn(string $line): bool => strlen($line) > SmtpProtocol::SMTP_LINE_LIMIT,
            ),
        );
    }

    #[Test]
    public function writesExpectedNumberOfLinesForLongHeaders(): void
    {
        $this->getTransport()->send($this->makeMessageWithLongHeaders());

        static::assertCount(28, explode("\r\n", $this->connection->getLog()));
    }

    #[Test]
    public function wrapsHeaderLongerThanLineLimit(): void
    {
        $this->getTransport()->send($this->makeMessageWithLongHeaders());

        static::assertStringNotContainsString(self::longHeaderValue(), $this->connection->getLog());
    }

    #[Test]
    public function leavesHeaderOfExactlyLineLimitUnwrapped(): void
    {
        $this->getTransport()->send($this->makeMessageWithLongHeaders());

        static::assertStringContainsString(self::exactLengthHeaderValue(), $this->connection->getLog());
    }

    #[Test]
    public function canUseAuthenticationExtensionsViaPluginManager(): void
    {
        $options    = new SmtpOptions(['connection_class' => 'login']);
        $transport  = new Smtp($options);
        $connection = $transport->plugin($options->getConnectionClass(), [
            'username' => 'matthew',
            'password' => self::TEST_AUTH_VALUE,
            'host'     => 'localhost',
        ]);

        static::assertInstanceOf(Login::class, $connection);
        static::assertSame(['matthew', self::TEST_AUTH_VALUE], [
            $connection->getUsername(),
            $connection->getPassword(),
        ]);
    }

    #[Test]
    public function autoDisconnectCanBeTurnedOff(): void
    {
        $this->getTransport()->setAutoDisconnect(false);

        static::assertFalse($this->getTransport()->getAutoDisconnect());
    }

    #[Test]
    public function autoDisconnectIsOnByDefault(): void
    {
        static::assertTrue($this->getTransport()->getAutoDisconnect());
    }

    #[Test]
    public function destructorEndsSessionWithAutoDisconnect(): void
    {
        $this->connection->connect();
        $this->transport = null;

        static::assertFalse($this->connection->hasSession());
    }

    #[Test]
    public function destructorKeepsConnectionWithoutAutoDisconnect(): void
    {
        $this->connection->connect();
        $this->getTransport()->setAutoDisconnect(false);
        $this->transport = null;

        static::assertTrue($this->connection->isConnected());
    }

    #[Test]
    public function disconnectClosesConnection(): void
    {
        $this->connection->connect();
        $this->getTransport()->disconnect();

        static::assertFalse($this->connection->isConnected());
    }

    #[Test]
    public function sendingStartsSession(): void
    {
        $this->getTransport()->send($this->makeMessage());

        static::assertTrue($this->connection->hasSession());
    }

    #[Test]
    public function sendingAfterDisconnectStartsNewSession(): void
    {
        $this->getTransport()->send($this->makeMessage());
        $this->connection->disconnect();

        $this->getTransport()->send($this->makeMessage());

        static::assertTrue($this->connection->hasSession());
    }

    #[Test]
    public function reconnectsWhenConnectionTimeLimitIsReached(): void
    {
        $options = new SmtpOptions();
        $options->setConnectionTimeLimit(5 * 3600);

        $transport = $this->getTransport();
        $transport->setOptions($options);

        $connectionMock = $this->getMockBuilder(SmtpProtocol::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['connect', 'helo', 'hasSession', 'mail', 'rcpt', 'data', 'rset'])
            ->getMock();

        $connectionMock->expects(self::exactly(2))->method('hasSession')->willReturnOnConsecutiveCalls(false, true);
        $connectionMock->expects(self::exactly(2))->method('connect');
        $connectionMock->expects(self::exactly(2))->method('helo');
        $connectionMock->expects(self::exactly(3))->method('mail');
        $connectionMock->expects(self::exactly(9))->method('rcpt');
        $connectionMock->expects(self::exactly(3))->method('data');
        $connectionMock->expects(self::exactly(1))->method('rset');

        $transport->setConnection($connectionMock);

        $pluginManagerMock = $this->getMockBuilder(SmtpPluginManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $pluginManagerMock->expects(self::once())->method('get')->willReturn($connectionMock);

        $transport->setPluginManager($pluginManagerMock);

        $transport->send($this->makeMessage());

        $connectedTimeProperty       = (new ReflectionClass($transport))->getProperty('connectedTime');
        $connectedTimeAfterFirstMail = $connectedTimeProperty->getValue($transport);
        static::assertNotNull($connectedTimeAfterFirstMail);

        $transport->send($this->makeMessage());
        static::assertSame($connectedTimeAfterFirstMail, $connectedTimeProperty->getValue($transport));

        $connectedTimeProperty->setValue($transport, time() - (10 * 3600));
        $transport->send($this->makeMessage());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function envelopeFromLogProvider(): array
    {
        return [
            'envelope sender'     => ['MAIL FROM:<mailer@example.com>'],
            'Cc recipient'        => ['RCPT TO:<matthew@example.com>'],
            'Bcc recipient'       => ['RCPT TO:<list@example.com>'],
            'From header in DATA' => ["From: test@example.com,\r\n Matthew <matthew@example.com>\r\n"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function envelopeToLogProvider(): array
    {
        return [
            'message sender'     => ['MAIL FROM:<ralph@example.com>'],
            'envelope recipient' => ['RCPT TO:<users@example.com>'],
            'To header in DATA'  => ['To: Example Test <test@example.com>'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function envelopeLogProvider(): array
    {
        return [
            'envelope sender'           => ['MAIL FROM:<mailer@example.com>'],
            'first envelope recipient'  => ['RCPT TO:<users@example.com>'],
            'second envelope recipient' => ['RCPT TO:<dev@example.com>'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function messageLogProvider(): array
    {
        return [
            'envelope sender from Sender' => ['MAIL FROM:<ralph@example.com>'],
            'To'                          => ["To: Example Test <test@example.com>\r\n"],
            'Subject'                     => ["Subject: Testing Contenir\\Mail\\Transport\\Sendmail\r\n"],
            'Cc'                          => ["Cc: matthew@example.com\r\n"],
            'From'                        => ["From: test@example.com,\r\n Matthew <matthew@example.com>\r\n"],
            'X-Foo-Bar'                   => ["X-Foo-Bar: Matthew\r\n"],
            'Sender'                      => ["Sender: Ralph Schindler <ralph@example.com>\r\n"],
            'body after blank line'       => ["\r\n\r\nThis is only a test."],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function encodedHeaderProvider(): array
    {
        return [
            'Subject'      => ["Subject: =?UTF-8?Q?Gr=C3=BC=C3=9Fe=20aus=20K=C3=B6ln?=\r\n"],
            'To with name' => ["To: =?UTF-8?Q?J=C3=B6s=C3=A9?= <test@example.com>\r\n"],
        ];
    }

    private function getTransport(): Smtp
    {
        static::assertNotNull($this->transport);

        return $this->transport;
    }

    private function makeMessage(): Message
    {
        return (new Message())->addTo('test@example.com', 'Example Test')
            ->addCc('matthew@example.com')
            ->addBcc('list@example.com', 'Example List')
            ->addFrom([
                'test@example.com',
                'matthew@example.com' => 'Matthew',
            ])
            ->setSender('ralph@example.com', 'Ralph Schindler')
            ->setSubject('Testing Contenir\Mail\Transport\Sendmail')
            ->setBody('This is only a test.')
            ->addHeader(new GenericHeader('X-Foo-Bar', 'Matthew'));
    }

    private function makeDatedMessage(): Message
    {
        return new Message(new Headers(new Date(new DateTimeImmutable('Sun, 10 Jun 2012 20:07:24 +0200'))));
    }

    private function makeMessageWithLongHeaders(): Message
    {
        return $this->makeMessage()
            ->addHeader(new GenericHeader('X-Ms-Exchange-Antispam-Messagedata', self::longHeaderValue()))
            ->addHeader(new GenericHeader('X-Exact-Length', self::exactLengthHeaderValue()));
    }

    /**
     * A value the size of PHP_SOCK_CHUNK_SIZE (8192 bytes).
     */
    private static function longHeaderValue(): string
    {
        return str_repeat('0123456789abcdef', times: 512);
    }

    private static function exactLengthHeaderValue(): string
    {
        return substr(
            self::longHeaderValue(),
            offset: 0,
            length: SmtpProtocol::SMTP_LINE_LIMIT - strlen('X-Exact-Length: '),
        );
    }
}
