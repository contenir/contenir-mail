<?php

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use Contenir\Mail\Protocol\Exception;
use Contenir\Mail\Tests\Unit\TestAsset\SmtpProtocolSpy;
use Contenir\Mail\Transport\Smtp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Contenir\Mail\Protocol\Smtp::class)]
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

    #[Test]
    public function sendMinimalMail(): void
    {
        $headers = new Headers();
        $headers->addHeaderLine('Date', 'Sun, 10 Jun 2012 20:07:24 +0200');

        $message = new Message();
        $message->setHeaders($headers);
        $message->setSender('sender@example.com', 'Example Sender');
        $message->setBody('testSendMailWithoutMinimalHeaders');
        $message->addTo('recipient@example.com', 'Recipient Name');

        $expectedMessage =
            "EHLO localhost\r\n"
            . "MAIL FROM:<sender@example.com>\r\n"
            . "RCPT TO:<recipient@example.com>\r\n"
            . "DATA\r\n"
            . "Date: Sun, 10 Jun 2012 20:07:24 +0200\r\n"
            . "Sender: Example Sender <sender@example.com>\r\n"
            . "To: Recipient Name <recipient@example.com>\r\n"
            . "\r\n"
            . "testSendMailWithoutMinimalHeaders\r\n"
            . ".\r\n";

        $this->transport->send($message);

        static::assertEquals($expectedMessage, $this->connection->getLog());
    }

    #[Test]
    public function sendEscapedEmail(): void
    {
        $headers = new Headers();
        $headers->addHeaderLine('Date', 'Sun, 10 Jun 2012 20:07:24 +0200');

        $message = new Message();
        $message->setHeaders($headers);
        $message->setSender('sender@example.com', 'Example Sender');
        $message->setBody("This is a test\n.");
        $message->addTo('recipient@example.com', 'Recipient Name');

        $expectedMessage =
            "EHLO localhost\r\n"
            . "MAIL FROM:<sender@example.com>\r\n"
            . "RCPT TO:<recipient@example.com>\r\n"
            . "DATA\r\n"
            . "Date: Sun, 10 Jun 2012 20:07:24 +0200\r\n"
            . "Sender: Example Sender <sender@example.com>\r\n"
            . "To: Recipient Name <recipient@example.com>\r\n"
            . "\r\n"
            . "This is a test\r\n"
            . "..\r\n"
            . ".\r\n";

        $this->transport->send($message);

        static::assertEquals($expectedMessage, $this->connection->getLog());
    }

    #[Test]
    public function disconnectCallsQuit(): void
    {
        $this->connection->disconnect();
        static::assertTrue($this->connection->calledQuit);
    }

    #[Test]
    public function disconnectResetsAuthFlag(): void
    {
        $this->connection->connect();
        $this->connection->setSessionStatus(true);
        $this->connection->setAuth(true);
        static::assertTrue($this->connection->getAuth());
        $this->connection->disconnect();
        static::assertFalse($this->connection->getAuth());
    }

    #[Test]
    public function connectHasVerboseErrors(): void
    {
        $smtp = new TestAsset\ErroneousSmtp();

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessageMatches('/nonexistentremote/');

        $smtp->connect('nonexistentremote');
    }

    #[Test]
    public function canAvoidQuitRequest(): void
    {
        static::assertTrue($this->connection->useCompleteQuit(), 'Default behaviour must be BC');

        $this->connection->resetLog();
        $this->connection->connect();
        $this->connection->helo();
        $this->connection->disconnect();

        static::assertStringContainsString('QUIT', $this->connection->getLog());

        $this->connection->setUseCompleteQuit(false);
        static::assertFalse($this->connection->useCompleteQuit());

        $this->connection->resetLog();
        $this->connection->connect();
        $this->connection->helo();
        $this->connection->disconnect();

        static::assertStringNotContainsString('QUIT', $this->connection->getLog());

        $connection = new SmtpProtocolSpy([
            'use_complete_quit' => false,
        ]);
        static::assertFalse($connection->useCompleteQuit());
    }

    #[Test]
    public function authThrowsWhenAlreadyAuthed(): void
    {
        $this->connection->setAuth(true);
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Already authenticated for this session');
        $this->connection->auth();
    }

    #[Test]
    public function heloThrowsWhenAlreadySession(): void
    {
        $this->connection->helo('hostname.test');
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Cannot issue HELO to existing session');
        $this->connection->helo('hostname.test');
    }

    #[Test]
    public function heloThrowsWithInvalidHostname(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('The input does not match the expected structure for a DNS hostname');
        $this->connection->helo("invalid\r\nhost name");
    }

    #[Test]
    public function mailThrowsWhenNoSession(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('A valid session has not been started');
        $this->connection->mail('test@example.com');
    }

    #[Test]
    public function rcptThrowsWhenNoMail(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('No sender reverse path has been supplied');
        $this->connection->rcpt('test@example.com');
    }

    #[Test]
    public function dataThrowsWhenNoRcpt(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('No recipient forward path has been supplied');
        $this->connection->data('message');
    }

    #[Test]
    public function rcptThrowsWithCodeWhenErroneousRecipient(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage(
            SmtpProtocolSpy::ERRONEOUS_RECIPIENT_ENHANCED_CODE . ' ' . SmtpProtocolSpy::ERRONEOUS_RECIPIENT_MESSAGE,
        );
        $this->expectExceptionCode(SmtpProtocolSpy::ERRONEOUS_RECIPIENT_CODE);

        $headers = new Headers();
        $headers->addHeaderLine('Date', 'Sun, 10 Jun 2012 20:07:24 +0200');

        $message = new Message();
        $message->setHeaders($headers);
        $message->setSender('sender@example.com', 'Example Sender');
        $message->setBody("This is a test\n.");
        $message->addTo(SmtpProtocolSpy::ERRONEOUS_RECIPIENT, 'Erroneous Recipient Name');

        $this->transport->send($message);
    }
}
