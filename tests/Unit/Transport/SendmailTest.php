<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Message;
use Contenir\Mail\Transport\Exception\RuntimeException;
use Contenir\Mail\Transport\Sendmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sendmail::class)]
#[Group('unit')]
final class SendmailTest extends TestCase
{
    #[Test]
    public function passesToHeaderValueAsRecipientOnUnixSystems(): void
    {
        $mail = $this->send($this->makeUnixTransport(), $this->makeMessage());

        static::assertSame('Example Test <test@example.com>', $mail['to']);
    }

    #[Test]
    public function passesEncodedToHeaderValueAsRecipientOnUnixSystems(): void
    {
        $message = $this->makeMessage()->setTo(['a@example.com' => 'Jösé', 'b@example.com' => null]);

        $mail = $this->send($this->makeUnixTransport(), $message);

        static::assertSame("=?UTF-8?Q?J=C3=B6s=C3=A9?= <a@example.com>,\r\n b@example.com", $mail['to']);
    }

    #[Test]
    public function passesBareEmailAddressesAsRecipientsOnWindowsSystems(): void
    {
        $message = $this->makeMessage()->setTo(['a@example.com' => 'First', 'b@example.com' => null]);

        $mail = $this->send($this->makeWindowsTransport(), $message);

        static::assertSame('a@example.com, b@example.com', $mail['to']);
    }

    #[DataProvider('transportProvider')]
    #[Test]
    public function passesSubjectSeparately(string $system): void
    {
        $mail = $this->send($this->makeTransport($system), $this->makeMessage());

        static::assertSame('Testing Contenir\Mail\Transport\Sendmail', $mail['subject']);
    }

    #[Test]
    public function encodesNonAsciiSubject(): void
    {
        $message = $this->makeMessage()->setSubject('Testing Sendmäil');

        $mail = $this->send($this->makeUnixTransport(), $message);

        static::assertSame('=?UTF-8?Q?Testing=20Sendm=C3=A4il?=', $mail['subject']);
    }

    #[Test]
    public function leavesAsciiSubjectUnencodedWhenBodyEncodingIsUtf8(): void
    {
        $message = $this->makeMessage()->setEncoding('UTF-8');

        $mail = $this->send($this->makeUnixTransport(), $message);

        static::assertSame('Testing Contenir\Mail\Transport\Sendmail', $mail['subject']);
    }

    #[DataProvider('transportProvider')]
    #[Test]
    public function passesBodySeparately(string $system): void
    {
        $mail = $this->send($this->makeTransport($system), $this->makeMessage());

        static::assertSame('This is only a test.', $mail['body']);
    }

    #[Test]
    public function doublesFullStopsStartingLinesOnWindowsSystems(): void
    {
        $message = $this->makeMessage()->setBody("This is the first line.\n. This is the second");

        $mail = $this->send($this->makeWindowsTransport(), $message);

        static::assertSame("This is the first line.\n.. This is the second", $mail['body']);
    }

    #[Test]
    public function leavesFullStopsStartingLinesOnUnixSystems(): void
    {
        $message = $this->makeMessage()->setBody("This is the first line.\n. This is the second");

        $mail = $this->send($this->makeUnixTransport(), $message);

        static::assertSame("This is the first line.\n. This is the second", $mail['body']);
    }

    #[DataProvider('additionalHeaderProvider')]
    #[Test]
    public function passesOtherHeadersAsAdditionalHeaders(string $system, string $expected): void
    {
        $mail = $this->send($this->makeTransport($system), $this->makeMessage());

        static::assertStringContainsString($expected, $mail['headers']);
    }

    /**
     * @see https://github.com/laminas/laminas-mail/issues/19
     */
    #[DataProvider('separateHeaderProvider')]
    #[Test]
    public function doesNotRepeatToAndSubjectInAdditionalHeaders(string $system, string $pattern): void
    {
        $mail = $this->send($this->makeTransport($system), $this->makeMessage());

        static::assertDoesNotMatchRegularExpression($pattern, $mail['headers']);
    }

    #[Test]
    public function passesSenderAsEnvelopeSenderAfterParameters(): void
    {
        $transport = $this->makeUnixTransport();
        $transport->setParameters('-R hdrs');

        $mail = $this->send($transport, $this->makeMessage());

        static::assertSame("-R hdrs -f'ralph@example.com'", $mail['parameters']);
    }

    #[Test]
    public function joinsAndTrimsParameterList(): void
    {
        $transport = $this->makeUnixTransport();
        $transport->setParameters([' -R', 'hdrs ']);

        $mail = $this->send($transport, $this->makeMessage());

        static::assertSame("-R hdrs -f'ralph@example.com'", $mail['parameters']);
    }

    #[Test]
    public function passesFirstFromAddressAsEnvelopeSenderWithoutSender(): void
    {
        $message = $this->makeMessage()->removeHeader('Sender');

        $mail = $this->send($this->makeUnixTransport(), $message);

        static::assertSame(" -f'test@example.com'", $mail['parameters']);
    }

    #[Test]
    public function passesNoEnvelopeSenderWithoutSenderOrFrom(): void
    {
        $message = $this->makeMessage()->removeHeader('Sender')->removeHeader('From');

        $mail = $this->send($this->makeUnixTransport(), $message);

        static::assertSame('', $mail['parameters']);
    }

    #[Test]
    public function passesNoParametersOnWindowsSystems(): void
    {
        $mail = $this->send($this->makeWindowsTransport(), $this->makeMessage());

        static::assertSame('', $mail['parameters']);
    }

    /**
     * @ref CVE-2016-10033 which targeted WordPress
     */
    #[Test]
    public function escapesSenderForTheShell(): void
    {
        $message = $this->makeMessage()->setSender("o'brien@example.com");

        $mail = $this->send($this->makeUnixTransport(), $message);

        static::assertSame(" -f'o'\\''brien@example.com'", $mail['parameters']);
    }

    /**
     * @ref CVE-2016-10033 which targeted WordPress
     */
    #[Test]
    public function escapesFromAddressForTheShell(): void
    {
        $message = $this->makeMessage()->removeHeader('Sender')->setFrom("o'brien@example.com");

        $mail = $this->send($this->makeUnixTransport(), $message);

        static::assertSame(" -f'o'\\''brien@example.com'", $mail['parameters']);
    }

    #[DataProvider('fromSwitchProvider')]
    #[Test]
    public function keepsFromSwitchAlreadyInParameters(string $parameters): void
    {
        $transport = $this->makeUnixTransport();
        $transport->setParameters($parameters);

        $mail = $this->send($transport, $this->makeMessage());

        static::assertSame($parameters, $mail['parameters']);
    }

    #[Test]
    public function rejectsCodeInjectionInFromHeader(): void
    {
        $message = $this->makeMessage()
            ->setBody('This is the text of the email.')
            ->setFrom('"AAA\" code injection"@domain', "Sender's name")
            ->addTo('hacker@localhost', 'Name of recipient')
            ->setSubject('TestSubject');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Potential code injection in From header');

        $this->send($this->makeUnixTransport(), $message);
    }

    #[Test]
    public function acceptsQuotedLocalPartInFromHeader(): void
    {
        $message = $this->makeMessage()
            ->setBody('This is the text of the email.')
            ->setFrom('"foo-bar"@domain', 'Foo Bar')
            ->addTo('hacker@localhost', 'Name of recipient')
            ->setSubject('TestSubject');

        $mail = $this->send($this->makeUnixTransport(), $message);

        static::assertStringContainsString("From: Foo Bar <\"foo-bar\"@domain>\r\n", $mail['headers']);
    }

    #[DataProvider('recipientWithoutToProvider')]
    #[Test]
    public function sendsMessageWithoutToHeader(string $method): void
    {
        $message = (new Message())->setSender('ralph@example.com', 'Ralph Schindler')
            ->setSubject('Testing Contenir\Mail\Transport\Sendmail')
            ->setBody('This is only a test.');
        $message->{$method}('list@example.com', 'Example, List');

        $mail = $this->send($this->makeUnixTransport(), $message);

        static::assertSame('', $mail['to']);
    }

    #[Test]
    public function rejectsMessageWithoutToCcAndBccHeaders(): void
    {
        $message = (new Message())->setSender('ralph@example.com', 'Ralph Schindler')
            ->setSubject('Testing Contenir\Mail\Transport\Sendmail')
            ->setBody('This is only a test.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid email; contains no at least one of "To", "Cc", and "Bcc" header');

        $this->send($this->makeUnixTransport(), $message);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function transportProvider(): array
    {
        return [
            'unix'    => ['unix'],
            'windows' => ['windows'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function additionalHeaderProvider(): array
    {
        $headers = [
            'Cc'        => "Cc: matthew@example.com\r\n",
            'Bcc'       => "Bcc: \"Example, List\" <list@example.com>\r\n",
            'From'      => "From: test@example.com,\r\n Matthew <matthew@example.com>\r\n",
            'X-Foo-Bar' => "X-Foo-Bar: Matthew\r\n",
            'Sender'    => "Sender: Ralph Schindler <ralph@example.com>\r\n",
        ];

        $cases = [];
        foreach (['unix', 'windows'] as $system) {
            foreach ($headers as $name => $expected) {
                $cases["{$name} on {$system}"] = [$system, $expected];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function separateHeaderProvider(): array
    {
        $patterns = [
            'To'      => '/^To:/m',
            'Subject' => '/^Subject:/m',
        ];

        $cases = [];
        foreach (['unix', 'windows'] as $system) {
            foreach ($patterns as $name => $pattern) {
                $cases["{$name} on {$system}"] = [$system, $pattern];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fromSwitchProvider(): array
    {
        return [
            'leading'     => ["-f'foo@example.com'"],
            'not leading' => ["-bs -f'foo@example.com'"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function recipientWithoutToProvider(): array
    {
        return [
            'Cc only'  => ['addCc'],
            'Bcc only' => ['addBcc'],
        ];
    }

    private function makeMessage(): Message
    {
        return (new Message())->addTo('test@example.com', 'Example Test')
            ->addCc('matthew@example.com')
            ->addBcc('list@example.com', 'Example, List')
            ->addFrom([
                'test@example.com',
                'matthew@example.com' => 'Matthew',
            ])
            ->setSender('ralph@example.com', 'Ralph Schindler')
            ->setSubject('Testing Contenir\Mail\Transport\Sendmail')
            ->setBody('This is only a test.')
            ->addHeader(new GenericHeader('X-Foo-Bar', 'Matthew'));
    }

    private function makeTransport(string $system): Sendmail
    {
        return 'windows' === $system ? $this->makeWindowsTransport() : $this->makeUnixTransport();
    }

    private function makeUnixTransport(): Sendmail
    {
        return new class extends Sendmail {
            /** @var string */
            protected $operatingSystem = 'LIN';
        };
    }

    private function makeWindowsTransport(): Sendmail
    {
        return new class extends Sendmail {
            /** @var string */
            protected $operatingSystem = 'WIN';
        };
    }

    /**
     * Send through the transport and capture the arguments it would pass to mail().
     *
     * @return array{to: string, subject: string, body: string, headers: string, parameters: null|string}
     */
    private function send(Sendmail $transport, Message $message): array
    {
        $captured = ['to' => '', 'subject' => '', 'body' => '', 'headers' => '', 'parameters' => null];
        $transport->setCallable(static function (
            string $to,
            string $subject,
            string $body,
            string $headers,
            ?string $parameters = null,
        ) use (&$captured): void {
            $captured = [
                'to'         => $to,
                'subject'    => $subject,
                'body'       => $body,
                'headers'    => $headers,
                'parameters' => $parameters,
            ];
        });

        $transport->send($message);

        return $captured;
    }
}
