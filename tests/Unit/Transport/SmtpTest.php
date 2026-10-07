<?php

namespace Contenir\Mail\Tests\Unit\Transport;

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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function explode;
use function str_repeat;
use function strlen;
use function substr;
use function time;

#[CoversClass(Smtp::class)]
class SmtpTest extends TestCase
{
    /** @var Smtp */
    public $transport;
    /** @var SmtpProtocolSpy */
    public $connection;

    public function setUp(): void
    {
        $this->transport  = new Smtp();
        $this->connection = new SmtpProtocolSpy();
        $this->transport->setConnection($this->connection);
    }

    public function getMessage(): Message
    {
        $message = new Message();
        $message->addTo('test@example.com', 'Example Test');
        $message->addCc('matthew@example.com');
        $message->addBcc('list@example.com', 'Example List');
        $message->addFrom([
            'test@example.com',
            'matthew@example.com' => 'Matthew',
        ]);
        $message->setSender('ralph@example.com', 'Ralph Schindler');
        $message->setSubject('Testing Contenir\Mail\Transport\Sendmail');
        $message->setBody('This is only a test.');

        $message->getHeaders()
            ->addHeaders([
                'X-Foo-Bar' => 'Matthew',
            ]);

        return $message;
    }

    /**
     *  Per RFC 2822 3.6
     */
    #[Test]
    public function sendMailWithoutMinimalHeaders(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage(
            'transport expects either a Sender or at least one From address in the Message; none provided',
        );
        $message = new Message();
        $this->transport->send($message);
    }

    /**
     *  Per RFC 2821 3.3 (page 18)
     *  - RCPT (recipient) must be called before DATA (headers or body)
     */
    #[Test]
    public function sendMailWithoutRecipient(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('at least one recipient if the message has at least one header or body');
        $message = new Message();
        $message->setSender('ralph@example.com', 'Ralph Schindler');
        $this->transport->send($message);
    }

    #[Test]
    public function sendMailWithEnvelopeFrom(): void
    {
        $message  = $this->getMessage();
        $envelope = new Envelope([
            'from' => 'mailer@example.com',
        ]);
        $this->transport->setEnvelope($envelope);
        $this->transport->send($message);

        $data = $this->connection->getLog();
        static::assertStringContainsString('MAIL FROM:<mailer@example.com>', $data);
        static::assertStringContainsString('RCPT TO:<matthew@example.com>', $data);
        static::assertStringContainsString('RCPT TO:<list@example.com>', $data);
        static::assertStringContainsString("From: test@example.com,\r\n Matthew <matthew@example.com>\r\n", $data);
    }

    #[Test]
    public function sendMailWithEnvelopeTo(): void
    {
        $message  = $this->getMessage();
        $envelope = new Envelope([
            'to' => 'users@example.com',
        ]);
        $this->transport->setEnvelope($envelope);
        $this->transport->send($message);

        $data = $this->connection->getLog();
        static::assertStringContainsString('MAIL FROM:<ralph@example.com>', $data);
        static::assertStringContainsString('RCPT TO:<users@example.com>', $data);
        static::assertStringContainsString('To: Example Test <test@example.com>', $data);
    }

    #[Test]
    public function sendMailWithEnvelope(): void
    {
        $message  = $this->getMessage();
        $to       = ['users@example.com', 'dev@example.com'];
        $envelope = new Envelope([
            'from' => 'mailer@example.com',
            'to'   => $to,
        ]);
        $this->transport->setEnvelope($envelope);
        $this->transport->send($message);

        static::assertSame($to, $this->connection->getRecipients());

        $data = $this->connection->getLog();
        static::assertStringContainsString('MAIL FROM:<mailer@example.com>', $data);
        static::assertStringContainsString('RCPT TO:<users@example.com>', $data);
        static::assertStringContainsString('RCPT TO:<dev@example.com>', $data);
    }

    #[Test]
    public function sendMinimalMail(): void
    {
        $headers = new Headers();
        $headers->addHeaderLine('Date', 'Sun, 10 Jun 2012 20:07:24 +0200');

        $message = new Message();
        $message->setHeaders($headers);
        $message->setSender('ralph@example.com', 'Ralph Schindler');
        $message->setBody('testSendMailWithoutMinimalHeaders');
        $message->addTo('test@example.com', 'Example Test');

        $expectedMessage =
            "Date: Sun, 10 Jun 2012 20:07:24 +0200\r\n"
            . "Sender: Ralph Schindler <ralph@example.com>\r\n"
            . "To: Example Test <test@example.com>\r\n"
            . "\r\n"
            . 'testSendMailWithoutMinimalHeaders';

        $this->transport->send($message);

        static::assertStringContainsString($expectedMessage, $this->connection->getLog());
    }

    #[Test]
    public function sendMinimalMailWithoutSender(): void
    {
        $headers = new Headers();
        $headers->addHeaderLine('Date', 'Sun, 10 Jun 2012 20:07:24 +0200');

        $message = new Message();
        $message->setHeaders($headers);
        $message->setFrom('ralph@example.com', 'Ralph Schindler');
        $message->setBody('testSendMinimalMailWithoutSender');
        $message->addTo('test@example.com', 'Example Test');

        $expectedMessage =
            "Date: Sun, 10 Jun 2012 20:07:24 +0200\r\n"
            . "From: Ralph Schindler <ralph@example.com>\r\n"
            . "To: Example Test <test@example.com>\r\n"
            . "\r\n"
            . 'testSendMinimalMailWithoutSender';

        $this->transport->send($message);

        static::assertStringContainsString($expectedMessage, $this->connection->getLog());
    }

    #[Test]
    public function receivesMailArtifacts(): void
    {
        $message = $this->getMessage();
        $this->transport->send($message);

        $expectedRecipients = ['test@example.com', 'matthew@example.com', 'list@example.com'];
        static::assertSame($expectedRecipients, $this->connection->getRecipients());

        $data = $this->connection->getLog();
        static::assertStringContainsString('MAIL FROM:<ralph@example.com>', $data);
        static::assertStringContainsString('To: Example Test <test@example.com>', $data);
        static::assertStringContainsString('Subject: Testing Contenir\Mail\Transport\Sendmail', $data);
        static::assertStringContainsString("Cc: matthew@example.com\r\n", $data);
        static::assertStringNotContainsString("Bcc: \"Example List\" <list@example.com>\r\n", $data);
        static::assertStringContainsString("From: test@example.com,\r\n Matthew <matthew@example.com>\r\n", $data);
        static::assertStringContainsString("X-Foo-Bar: Matthew\r\n", $data);
        static::assertStringContainsString("Sender: Ralph Schindler <ralph@example.com>\r\n", $data);
        static::assertStringContainsString("\r\n\r\nThis is only a test.", $data, $data);
    }

    /**
     * Fold long lines during smtp communication in Protocol\Smtp class.
     * Test folding of long lines following RFC 5322 section-2.2.3
     *
     * @see https://github.com/laminas/laminas-mail/pull/140
     */
    #[Test]
    public function longLinesFoldingRFC5322(): void
    {
        $message = 'The folding logic expects exactly 1 byte after \r\n in folding';
        static::assertSame("\r\n ", Headers::FOLDING, $message);

        $message = $this->getMessage();
        // Create buffer of 8192 bytes (PHP_SOCK_CHUNK_SIZE)
        $buffer = str_repeat('0123456789abcdef', 512);

        $maxLen                         = SmtpProtocol::SMTP_LINE_LIMIT;
        $headerWithLargeValue           = $buffer;
        $headerWithExactlyMaxLineLength = substr($buffer, 0, $maxLen - strlen('X-Exact-Length: '));
        $message->getHeaders()
            ->addHeaders([
                'X-Ms-Exchange-Antispam-Messagedata' => $headerWithLargeValue,
                'X-Exact-Length'                     => $headerWithExactlyMaxLineLength,
            ]);

        $this->transport->send($message);
        $data = $this->connection->getLog();

        $lines = explode("\r\n", $data);
        static::assertCount(28, $lines);

        foreach ($lines as $line) {
            static::assertLessThanOrEqual($maxLen, strlen($line), "Line is too long: {$line}");
        }

        static::assertStringNotContainsString(
            $headerWithLargeValue,
            $data,
            "The original header can't be present if it's wrapped",
        );
        static::assertStringContainsString(
            $headerWithExactlyMaxLineLength,
            $data,
            'Header with exact length is not wrapped',
        );
    }

    #[Test]
    public function canUseAuthenticationExtensionsViaPluginManager(): void
    {
        $options = new SmtpOptions([
            'connection_class' => 'login',
        ]);
        $transport  = new Smtp($options);
        $connection = $transport->plugin($options->getConnectionClass(), [
            'username' => 'matthew',
            'password' => 'password',
            'host'     => 'localhost',
        ]);
        static::assertInstanceOf(Login::class, $connection);
        static::assertSame('matthew', $connection->getUsername());
        static::assertSame('password', $connection->getPassword());
    }

    #[Test]
    public function setAutoDisconnect(): void
    {
        $this->transport->setAutoDisconnect(false);
        static::assertFalse($this->transport->getAutoDisconnect());
    }

    #[Test]
    public function getDefaultAutoDisconnectValue(): void
    {
        static::assertTrue($this->transport->getAutoDisconnect());
    }

    #[Test]
    public function autoDisconnectTrue(): void
    {
        $this->connection->connect();
        unset($this->transport);
        static::assertFalse($this->connection->hasSession());
    }

    #[Test]
    public function autoDisconnectFalse(): void
    {
        $this->connection->connect();
        $this->transport->setAutoDisconnect(false);
        unset($this->transport);
        static::assertTrue($this->connection->isConnected());
    }

    #[Test]
    public function disconnect(): void
    {
        $this->connection->connect();
        static::assertTrue($this->connection->isConnected());
        $this->transport->disconnect();
        static::assertFalse($this->connection->isConnected());
    }

    #[Test]
    public function disconnectSendReconnects(): void
    {
        static::assertFalse($this->connection->hasSession());
        $this->transport->send($this->getMessage());
        static::assertTrue($this->connection->hasSession());
        $this->connection->disconnect();

        static::assertFalse($this->connection->hasSession());
        $this->transport->send($this->getMessage());
        static::assertTrue($this->connection->hasSession());
    }

    #[Test]
    public function autoReconnect(): void
    {
        $options = new SmtpOptions();
        $options->setConnectionTimeLimit(5 * 3600);

        $this->transport->setOptions($options);

        // Mock the connection
        $connectionMock = $this->getMockBuilder(SmtpProtocol::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['connect', 'helo', 'hasSession', 'mail', 'rcpt', 'data', 'rset'])
            ->getMock();

        $connectionMock->expects(self::exactly(2))
            ->method('hasSession')
            ->willReturnOnConsecutiveCalls(
                false,
                true,
            );

        $connectionMock->expects(self::exactly(2))
            ->method('connect');

        $connectionMock->expects(self::exactly(2))
            ->method('helo');

        $connectionMock->expects(self::exactly(3))
            ->method('mail');

        $connectionMock->expects(self::exactly(9))
            ->method('rcpt');

        $connectionMock->expects(self::exactly(3))
            ->method('data');

        $connectionMock->expects(self::exactly(1))
            ->method('rset');

        $this->transport->setConnection($connectionMock);

        // Mock the plugin manager so that lazyLoadConnection() works
        $pluginManagerMock = $this->getMockBuilder(SmtpPluginManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();

        $pluginManagerMock->expects(self::once())
            ->method('get')
            ->willReturn($connectionMock);

        $this->transport->setPluginManager($pluginManagerMock);

        // Send the first email - first connect()
        $this->transport->send($this->getMessage());

        // Check that the connectedTime was set properly
        $reflClass             = new ReflectionClass($this->transport);
        $connectedTimeProperty = $reflClass->getProperty('connectedTime');

        static::assertNotNull($connectedTimeProperty);
        $connectedTimeAfterFirstMail = $connectedTimeProperty->getValue($this->transport);
        static::assertNotNull($connectedTimeAfterFirstMail);

        // Send the second email - no new connect()
        $this->transport->send($this->getMessage());

        // Make sure that there was no new connect() (and no new timestamp was written)
        static::assertSame($connectedTimeAfterFirstMail, $connectedTimeProperty->getValue($this->transport));

        // Manipulate the timestamp to trigger the auto-reconnect
        $connectedTimeProperty->setValue($this->transport, time() - (10 * 3600));

        // Send the third email - it should trigger a new connect()
        $this->transport->send($this->getMessage());
    }
}
