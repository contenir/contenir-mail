<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use ArrayObject;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp;
use Contenir\Mail\Protocol\Smtp\Chunks;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\TestAsset\RecordingWriteStream;
use Contenir\Mail\Tests\Unit\TestAsset\SmtpServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_slice;
use function fclose;
use function fopen;
use function fwrite;
use function str_contains;
use function str_repeat;
use function stream_socket_pair;
use function strlen;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/**
 * DATA sent in chunks: from a string or a stream, with one entry in the log for the whole message.
 */
#[CoversClass(Smtp::class)]
#[Group('unit')]
final class SmtpDataTest extends TestCase
{
    /** Lines of 99 bytes and a line feed, so that a line starts exactly at the chunk boundary */
    private const string LINE = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n";

    #[Test]
    public function doublesLeadingDotOfLineThatStartsANewChunk(): void
    {
        $server = new SmtpServer();
        $smtp   = self::open($server);
        $smtp->data(self::upToChunkEnd() . ".hidden\n");

        static::assertSame(['..hidden', '.'], array_slice(self::message($server), offset: -2));
    }

    #[Test]
    public function readsCrlfSplitBetweenChunksAsOneLineBreak(): void
    {
        $server = new SmtpServer();
        $smtp   = self::open($server);
        $smtp->data(self::upToChunkEnd("\r") . "\nnext\r\n");

        static::assertSame(['a', 'next', '.'], array_slice(self::message($server), offset: -3));
    }

    #[Test]
    public function logsTheMessageAsItsSizeInsteadOfItsText(): void
    {
        $smtp = self::open();
        $smtp->resetLog();
        $smtp->data(".secret\nline");

        static::assertSame(
            "DATA\r\n354 End data with <CR><LF>.<CR><LF>\r\n[DATA 16 bytes]\r\n.\r\n250 2.0.0 OK\r\n",
            $smtp->getLog(),
        );
    }

    #[Test]
    public function writesTheMessageInChunks(): void
    {
        $server = new SmtpServer();
        $smtp   = self::open($server);
        $smtp->resetLog();
        $smtp->data(str_repeat(self::LINE, times: 1000));

        static::assertSame(
            "DATA\r\n354 End data with <CR><LF>.<CR><LF>\r\n[DATA 101000 bytes]\r\n.\r\n250 2.0.0 OK\r\n",
            $smtp->getLog(),
        );
    }

    #[Test]
    public function sendsMessageReadFromStream(): void
    {
        $server = new SmtpServer();
        $smtp   = self::open($server);
        $smtp->dataFromStream(self::stream("Subject: Hi\n\n.Hello\r"));

        static::assertSame(['Subject: Hi', '', '..Hello', '.'], self::message($server));
    }

    #[Test]
    public function readsStreamFromItsStart(): void
    {
        $server = new SmtpServer();
        $stream = self::stream("Subject: Hi\r\n\r\nHello");
        fwrite($stream, data: "\r\nmore");
        $smtp = self::open($server);
        $smtp->dataFromStream($stream);

        static::assertSame(['Subject: Hi', '', 'Hello', 'more', '.'], self::message($server));
    }

    #[Test]
    public function sendsNothingForStreamWithLineLongerThanLimit(): void
    {
        $server = new SmtpServer();
        $smtp   = self::open($server);

        try {
            $smtp->dataFromStream(self::stream("ok\n" . str_repeat('a', Smtp::SMTP_LINE_LIMIT + 1)));
        } catch (InvalidArgumentException $e) {
            static::assertSame(
                [
                    'Line 2 of the message is 999 bytes; SMTP allows at most 998. Encode the content '
                        . '(quoted-printable or base64) instead of sending it as is.',
                    [],
                ],
                [$e->getMessage(), self::message($server)],
            );
            return;
        }

        static::fail('An over-long line was sent');
    }

    #[Test]
    public function refusesSomethingOtherThanAStream(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected an open stream');

        self::open()->dataFromStream('Subject: Hi');
    }

    #[Test]
    public function refusesClosedStream(): void
    {
        $stream = self::stream('Hello');
        fclose($stream);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected an open stream');

        self::open()->dataFromStream($stream);
    }

    #[Test]
    public function refusesStreamThatCannotBeReadTwice(): void
    {
        [$stream] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The message stream must be seekable');

        self::open()->dataFromStream($stream);
    }

    #[Test]
    public function refusesStreamThatCannotBeRead(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read the message stream');

        self::open()->dataFromStream(RecordingWriteStream::open(new ArrayObject(), accept: 1));
    }

    #[Test]
    public function refusesDataFromStreamWithoutRecipient(): void
    {
        $smtp = self::session();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No recipient forward path has been supplied');

        $smtp->dataFromStream(self::stream('Hello'));
    }

    #[Test]
    public function reportsServerThatGoesAwayDuringTheMessage(): void
    {
        $server = (new InMemoryConnection())->reply("220 mail.example.com ESMTP\r\n")
            ->expect("EHLO localhost\r\n")
            ->reply("250 mail.example.com\r\n")
            ->expect("MAIL FROM:<sender@example.com>\r\n")
            ->reply("250 OK\r\n")
            ->expect("RCPT TO:<recipient@example.com>\r\n")
            ->reply("250 OK\r\n")
            ->expect("DATA\r\n")
            ->reply("354 Go ahead\r\n")
            ->hangUp();
        $smtp = new Smtp(new ConnectionConfig('mail.example.com', security: Security::None), connection: $server);
        $smtp->connect();
        $smtp->helo('localhost');
        $smtp->mail('sender@example.com');
        $smtp->rcpt('recipient@example.com');

        try {
            $smtp->data('Hello');
        } catch (RuntimeException $e) {
            static::assertSame(
                ['Could not send request to mail.example.com', false],
                [$e->getMessage(), str_contains($smtp->getLog(), '[DATA')],
            );
            return;
        }

        static::fail('The message was sent to a server that went away');
    }

    /**
     * Lines that end with the last byte of the first chunk; the last line is "a" and its line break.
     */
    private static function upToChunkEnd(string $lineBreak = "\n"): string
    {
        $lines = str_repeat(self::LINE, (int) (Chunks::SIZE / strlen(self::LINE)) - 1);
        $fill  = Chunks::SIZE - strlen($lines) - strlen($lineBreak) - 2;

        return $lines . str_repeat('b', $fill) . "\n" . 'a' . $lineBreak;
    }

    /**
     * @return resource
     */
    private static function stream(string $content)
    {
        $stream = fopen('php://memory', mode: 'w+b');
        static::assertNotFalse($stream);
        fwrite($stream, $content);

        return $stream;
    }

    private static function session(?SmtpServer $server = null): Smtp
    {
        $smtp = new Smtp(
            new ConnectionConfig('mail.example.com', security: Security::None),
            connection: $server ?? new SmtpServer(),
        );
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
     * The lines sent after DATA.
     *
     * @return list<string>
     */
    private static function message(SmtpServer $server): array
    {
        return array_slice($server->sentLines(), offset: 4);
    }
}
