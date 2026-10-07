<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Message;
use Contenir\Mail\Tests\Trait\UsesTemporaryDirectoryTrait;
use Contenir\Mail\Transport\Exception\RuntimeException;
use Contenir\Mail\Transport\Sendmail;
use Contenir\Mail\Transport\SendmailConfig;
use Contenir\Mail\Transport\SendmailProcess;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function json_decode;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * The Sendmail transport with a configured program, run in place of mail().
 */
#[CoversClass(Sendmail::class)]
#[CoversClass(SendmailProcess::class)]
#[Group('unit')]
final class SendmailProgramTest extends TestCase
{
    use UsesTemporaryDirectoryTrait;

    private string $record = '';

    #[Override]
    protected function setUp(): void
    {
        $this->record = $this->setUpTemporaryDirectory() . DIRECTORY_SEPARATOR . 'record.json';
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    #[Test]
    public function passesEnvelopeAsSeparateArguments(): void
    {
        static::assertSame(
            ['-oi', '-f', 'ralph@example.com', '--', 'test@example.com', 'matthew@example.com', 'list@example.com'],
            $this->send(self::message())['argv'],
        );
    }

    #[Test]
    public function passesParametersBeforeEnvelope(): void
    {
        static::assertSame(
            [
                '-R',
                'hdrs',
                '-oi',
                '-f',
                'ralph@example.com',
                '--',
                'test@example.com',
                'matthew@example.com',
                'list@example.com',
            ],
            $this->send(self::message(), ['-R', 'hdrs'])['argv'],
        );
    }

    #[Test]
    public function passesFirstFromAddressAsEnvelopeSenderWithoutSender(): void
    {
        static::assertSame(
            ['-oi', '-f', 'test@example.com', '--', 'test@example.com', 'matthew@example.com', 'list@example.com'],
            $this->send(self::message()->removeHeader('Sender'))['argv'],
        );
    }

    #[Test]
    public function passesNoEnvelopeSenderWithoutSenderOrFrom(): void
    {
        static::assertSame(
            ['-oi', '--', 'test@example.com', 'matthew@example.com', 'list@example.com'],
            $this->send(self::message()->removeHeader('Sender')->removeHeader('From'))['argv'],
        );
    }

    /**
     * @param list<string> $parameters
     */
    #[DataProvider('fromSwitchProvider')]
    #[Test]
    public function keepsFromSwitchAlreadyInParameters(array $parameters): void
    {
        static::assertSame(
            [...$parameters, '-oi', '--', 'test@example.com', 'matthew@example.com', 'list@example.com'],
            $this->send(self::message(), $parameters)['argv'],
        );
    }

    /**
     * Without a shell, a sender that mail() must refuse is passed safely as one argument.
     */
    #[Test]
    public function passesQuotedSenderAsOneArgument(): void
    {
        $message = self::message()->setSender('"foo -X/tmp/x"@example.com');

        static::assertSame('"foo -X/tmp/x"@example.com', $this->send($message)['argv'][2]);
    }

    #[DataProvider('dashSenderProvider')]
    #[Test]
    public function refusesSenderThatWouldReadAsOption(Message $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The envelope sender must not start with "-", which sendmail would read as an option',
        );

        $this->send($message);
    }

    /**
     * Recipients follow "--", so one starting with "-" is not read as an option.
     */
    #[Test]
    public function passesRecipientStartingWithDashAfterSeparator(): void
    {
        $message = (new Message())->setFrom('ralph@example.com')
            ->setTo('-oQ/tmp/x@example.com');

        static::assertSame(
            ['-oi', '-f', 'ralph@example.com', '--', '-oQ/tmp/x@example.com'],
            $this->send($message)['argv'],
        );
    }

    #[Test]
    public function passesEachRecipientOnce(): void
    {
        $message = (new Message())->setTo('a@example.com')
            ->setCc(['b@example.com', 'a@example.com'])
            ->setBcc('b@example.com');

        static::assertSame(['-oi', '--', 'a@example.com', 'b@example.com'], $this->send($message)['argv']);
    }

    #[Test]
    public function writesMessageWithoutBccInLocalLineEndings(): void
    {
        $message = (new Message())->setTo('a@example.com')
            ->setBcc('hidden@example.com')
            ->setFrom('b@example.com')
            ->setSubject('Hi')
            ->setBody("Line 1\r\nLine 2")
            ->removeHeader('Date');

        static::assertSame(
            "To: a@example.com\nFrom: b@example.com\nSubject: Hi\n\nLine 1\nLine 2",
            $this->send($message)['stdin'],
        );
    }

    #[Test]
    public function runsProgramInsteadOfMailer(): void
    {
        $called    = false;
        $transport = new Sendmail(
            new SendmailConfig([dirname(__DIR__) . '/TestAsset/fake-sendmail.php', $this->record, 'ok'], PHP_BINARY),
            static function () use (&$called): void {
                $called = true;
            },
        );

        $transport->send(self::message());

        static::assertFalse($called);
    }

    #[Test]
    public function rejectsMessageWithoutRecipients(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid email; it has no To, Cc or Bcc address to send to');

        $this->send(
            (new Message())->setFrom('ralph@example.com')
                ->setBody('This is only a test.'),
        );
    }

    #[Test]
    public function reportsFailureOfProgram(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/failed with exit status 75: sendmail: cannot write the queue file$/D');

        $this->send(self::message(), mode: 'fail');
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function fromSwitchProvider(): array
    {
        return [
            'joined'   => [['-fbounces@example.com']],
            'separate' => [['-f', 'bounces@example.com']],
            'later'    => [['-oi', '-fbounces@example.com']],
        ];
    }

    /**
     * @return array<string, array{Message}>
     */
    public static function dashSenderProvider(): array
    {
        return [
            'Sender' => [self::message()->setSender('-X/tmp/log@example.com')],
            'From'   => [self::message()->removeHeader('Sender')->setFrom('-oQ/tmp@example.com')],
        ];
    }

    /**
     * Send through a transport that runs the fake sendmail, and return what it was given.
     *
     * @param list<string> $parameters
     * @return array{argv: list<string>, stdin: string}
     */
    private function send(Message $message, array $parameters = [], string $mode = 'ok'): array
    {
        $script = dirname(__DIR__) . '/TestAsset/fake-sendmail.php';
        (new Sendmail(new SendmailConfig([$script, $this->record, $mode, ...$parameters], PHP_BINARY)))->send($message);

        /** @var array{argv: list<string>, stdin: string} */
        return json_decode((string) file_get_contents($this->record), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    private static function message(): Message
    {
        return (new Message())->addTo('test@example.com', 'Example Test')
            ->addCc('matthew@example.com')
            ->addBcc('list@example.com', 'Example, List')
            ->addFrom(['test@example.com', 'matthew@example.com' => 'Matthew'])
            ->setSender('ralph@example.com', 'Ralph Schindler')
            ->setSubject('Testing Sendmail')
            ->setBody('This is only a test.');
    }
}
