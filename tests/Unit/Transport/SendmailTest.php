<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use ArrayIterator;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Multipart;
use Contenir\Mail\Mime\MultipartType;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Tests\Unit\TestAsset\InjectingHeader;
use Contenir\Mail\Transport\Exception\RuntimeException;
use Contenir\Mail\Transport\Sendmail;
use Contenir\Mail\Transport\SendmailConfig;
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
        static::assertSame('Example Test <test@example.com>', self::send(self::message())['to']);
    }

    #[Test]
    public function passesEncodedToHeaderValueAsRecipientOnUnixSystems(): void
    {
        $message = self::message()->setTo(['a@example.com' => 'Jösé', 'b@example.com' => null]);

        static::assertSame("=?UTF-8?Q?J=C3=B6s=C3=A9?= <a@example.com>,\r\n b@example.com", self::send($message)['to']);
    }

    #[Test]
    public function passesBareEmailAddressesAsRecipientsOnWindowsSystems(): void
    {
        $message = self::message()->setTo(['a@example.com' => 'First', 'b@example.com' => null]);

        static::assertSame('a@example.com, b@example.com', self::send($message, system: 'Windows')['to']);
    }

    #[DataProvider('systemProvider')]
    #[Test]
    public function passesSubjectSeparately(string $system): void
    {
        static::assertSame('Testing Sendmail', self::send(self::message(), system: $system)['subject']);
    }

    #[Test]
    public function passesEmptySubjectWithoutSubjectHeader(): void
    {
        static::assertSame('', self::send(self::message()->removeHeader('Subject'))['subject']);
    }

    #[Test]
    public function encodesNonAsciiSubject(): void
    {
        $message = self::message()->setSubject('Testing Sendmäil');

        static::assertSame('=?UTF-8?Q?Testing=20Sendm=C3=A4il?=', self::send($message)['subject']);
    }

    #[DataProvider('systemProvider')]
    #[Test]
    public function passesBodySeparately(string $system): void
    {
        static::assertSame('This is only a test.', self::send(self::message(), system: $system)['body']);
    }

    #[DataProvider('mimeHeaderProvider')]
    #[Test]
    public function passesMimeHeadersOfBuiltBodyAsAdditionalHeaders(string $expected): void
    {
        $message = self::message()
            ->setBody(
                new Multipart(MultipartType::Alternative, [Part::text('Hello'), Part::html('<p>Hello</p>')], 'alt'),
            );

        static::assertStringContainsString($expected, self::send($message)['headers']);
    }

    #[Test]
    public function doublesFullStopsStartingLinesOnWindowsSystems(): void
    {
        $message = self::message()->setBody("This is the first line.\n. This is the second");

        static::assertSame(
            "This is the first line.\n.. This is the second",
            self::send($message, system: 'Windows')['body'],
        );
    }

    #[Test]
    public function leavesFullStopsStartingLinesOnUnixSystems(): void
    {
        $message = self::message()->setBody("This is the first line.\n. This is the second");

        static::assertSame("This is the first line.\n. This is the second", self::send($message)['body']);
    }

    #[DataProvider('additionalHeaderProvider')]
    #[Test]
    public function passesOtherHeadersAsAdditionalHeaders(string $system, string $expected): void
    {
        static::assertStringContainsString($expected, self::send(self::message(), system: $system)['headers']);
    }

    /**
     * @see https://github.com/laminas/laminas-mail/issues/19
     */
    #[DataProvider('separateHeaderProvider')]
    #[Test]
    public function doesNotRepeatToAndSubjectInAdditionalHeaders(string $pattern): void
    {
        static::assertDoesNotMatchRegularExpression($pattern, self::send(self::message())['headers']);
    }

    #[Test]
    public function passesSenderAsEnvelopeSenderAfterParameters(): void
    {
        static::assertSame('-R hdrs -fralph@example.com', self::send(self::message(), '-R hdrs')['parameters']);
    }

    #[Test]
    public function passesFirstFromAddressAsEnvelopeSenderWithoutSender(): void
    {
        static::assertSame('-ftest@example.com', self::send(self::message()->removeHeader('Sender'))['parameters']);
    }

    #[Test]
    public function passesNoEnvelopeSenderWithoutSenderOrFrom(): void
    {
        $message = self::message()->removeHeader('Sender')->removeHeader('From');

        static::assertSame('-R hdrs', self::send($message, '-R hdrs')['parameters']);
    }

    #[Test]
    public function passesNoParametersOnWindowsSystems(): void
    {
        static::assertSame('', self::send(self::message(), '-R hdrs', 'Windows')['parameters']);
    }

    #[DataProvider('fromSwitchProvider')]
    #[Test]
    public function keepsFromSwitchAlreadyInParameters(string $parameters): void
    {
        static::assertSame($parameters, self::send(self::message(), $parameters)['parameters']);
    }

    /**
     * CVE-2016-10033 and CVE-2016-10045: an envelope sender that needs quoting is refused,
     * because mail() escapes the parameters again and the two escapings do not compose.
     */
    #[DataProvider('unsafeSenderProvider')]
    #[Test]
    public function refusesSenderUnsafeForCommandLine(string $sender): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The envelope sender cannot be passed safely to sendmail; it may only contain letters, digits '
                . 'and . _ + = - before the @. Set "-f" in the sendmail parameters to choose another.',
        );

        self::send(self::message()->setSender($sender));
    }

    #[Test]
    public function refusesUnsafeFromAddressWithoutSender(): void
    {
        $message = self::message()->removeHeader('Sender')->setFrom('"AAA\" code injection"@domain', "Sender's name");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The envelope sender cannot be passed safely to sendmail');

        self::send($message);
    }

    #[Test]
    public function acceptsUnusualSenderWhenParametersSetEnvelopeSender(): void
    {
        $message = self::message()->setFrom('"foo-bar"@example.com', 'Foo Bar');

        static::assertStringContainsString(
            "From: Foo Bar <\"foo-bar\"@example.com>\r\n",
            self::send($message->removeHeader('Sender'), '-fbounces@example.com')['headers'],
        );
    }

    #[DataProvider('safeSenderProvider')]
    #[Test]
    public function passesSafeSenderUnquoted(string $sender): void
    {
        static::assertSame("-f{$sender}", self::send(self::message()->setSender($sender))['parameters']);
    }

    /**
     * A custom header that writes its own line break could add headers the sender never set.
     */
    #[Test]
    public function refusesHeaderWithUnfoldedLineBreakAgainstHeaderInjection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Header "X-Custom" contains a line break that is not folding');

        self::send(self::message()->addHeader(new InjectingHeader()));
    }

    #[DataProvider('recipientWithoutToProvider')]
    #[Test]
    public function sendsMessageWithoutToHeader(string $method): void
    {
        $message = (new Message())->setSender('ralph@example.com')
            ->setBody('This is only a test.');
        $message->{$method}('list@example.com', 'Example, List');

        static::assertSame('', self::send($message)['to']);
    }

    #[Test]
    public function rejectsMessageWithoutToCcAndBccHeaders(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid email; contains no at least one of "To", "Cc", and "Bcc" header');

        self::send(
            (new Message())->setSender('ralph@example.com')
                ->setBody('This is only a test.'),
        );
    }

    #[Test]
    public function rejectsToHeaderWithoutAddresses(): void
    {
        $message = (new Message())->setSender('ralph@example.com')
            ->addHeader(new GenericHeader('To', 'undisclosed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid "To" header; contains no addresses');

        self::send($message);
    }

    /**
     * @param SendmailConfig|iterable<mixed, mixed>|string|null $config
     * @param list<string> $expected
     */
    #[DataProvider('configProvider')]
    #[Test]
    public function readsConfigInEveryForm(SendmailConfig|iterable|string|null $config, array $expected): void
    {
        static::assertSame($expected, (new Sendmail($config))->getConfig()->parameters);
    }

    #[Test]
    public function keepsGivenConfig(): void
    {
        $config = new SendmailConfig('-R hdrs');

        static::assertSame($config, (new Sendmail($config))->getConfig());
    }

    #[Test]
    public function rejectsUnknownSetting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown option "path"');

        new Sendmail(['path' => '/usr/sbin/sendmail']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function systemProvider(): array
    {
        return [
            'unix'    => ['Linux'],
            'windows' => ['Windows'],
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
        foreach (['Linux', 'Windows'] as $system) {
            foreach ($headers as $name => $expected) {
                $cases["{$name} on {$system}"] = [$system, $expected];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function separateHeaderProvider(): array
    {
        return [
            'To'      => ['/^To:/m'],
            'Subject' => ['/^Subject:/m'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mimeHeaderProvider(): array
    {
        return [
            'MIME-Version' => ["MIME-Version: 1.0\r\n"],
            'Content-Type' => ["Content-Type: multipart/alternative;\r\n boundary=\"alt\"\r\n"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fromSwitchProvider(): array
    {
        return [
            'leading'     => ['-ffoo@example.com'],
            'not leading' => ['-bs -ffoo@example.com'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeSenderProvider(): array
    {
        return [
            'single quote'      => ["o'brien@example.com"],
            'quoted local part' => ['"foo -X/tmp/x"@example.com'],
            'escaped quote'     => ['"AAA\" code injection"@example.com'],
            'leading dash'      => ['-X/tmp/log@example.com'],
            'dollar'            => ['$x@example.com'],
            'backtick'          => ['`id`@example.com'],
            'non-ASCII'         => ['jösé@example.com'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function safeSenderProvider(): array
    {
        return [
            'plain'           => ['ralph@example.com'],
            'plus and dots'   => ['ralph.s+list@mail.example.com'],
            'equals and dash' => ['bounce=x-y_z@example.com'],
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

    /**
     * @return array<string, array{SendmailConfig|iterable<mixed, mixed>|string|null, list<string>}>
     */
    public static function configProvider(): array
    {
        return [
            'null'                 => [null, []],
            'empty array'          => [[], []],
            'laminas string'       => ['-R hdrs', ['-R', 'hdrs']],
            'laminas list'         => [[' -R', 'hdrs '], ['-R', 'hdrs']],
            'laminas Traversable'  => [new ArrayIterator(['-R', 'hdrs']), ['-R', 'hdrs']],
            'settings'             => [['parameters' => '-R hdrs'], ['-R', 'hdrs']],
            'settings Traversable' => [new ArrayIterator(['parameters' => ['-R', 'hdrs']]), ['-R', 'hdrs']],
        ];
    }

    /**
     * Send through a transport and capture the arguments it would pass to mail().
     *
     * @return array{to: string, subject: string, body: string, headers: string, parameters: string}
     */
    private static function send(Message $message, string $parameters = '', string $system = 'Linux'): array
    {
        $captured  = ['to' => '', 'subject' => '', 'body' => '', 'headers' => '', 'parameters' => ''];
        $transport = new Sendmail(
            $parameters,
            static function (string $to, string $subject, string $body, string $headers, string $parameters) use (
                &$captured,
            ): void {
                $captured = [
                    'to'         => $to,
                    'subject'    => $subject,
                    'body'       => $body,
                    'headers'    => $headers,
                    'parameters' => $parameters,
                ];
            },
            $system,
        );

        $transport->send($message);

        return $captured;
    }

    private static function message(): Message
    {
        return (new Message())->addTo('test@example.com', 'Example Test')
            ->addCc('matthew@example.com')
            ->addBcc('list@example.com', 'Example, List')
            ->addFrom(['test@example.com', 'matthew@example.com' => 'Matthew'])
            ->setSender('ralph@example.com', 'Ralph Schindler')
            ->setSubject('Testing Sendmail')
            ->setBody('This is only a test.')
            ->addHeader(new GenericHeader('X-Foo-Bar', 'Matthew'));
    }
}
