<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Transport;

use Contenir\Mail\AddressGroup;
use Contenir\Mail\AddressList;
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Mime\TransferEncoding;
use Contenir\Mail\Protocol;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Smtp\Auth\Plain;
use Contenir\Mail\Storage;
use Contenir\Mail\Tests\Integration\TestAsset\Mailbox;
use Contenir\Mail\Tests\Integration\TestAsset\RecordingConnection;
use Contenir\Mail\Tests\Integration\TestAsset\Servers;
use Contenir\Mail\Transport\Smtp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_values;
use function bin2hex;
use function implode;
use function random_bytes;
use function range;
use function rtrim;
use function str_ends_with;
use function str_repeat;
use function str_starts_with;
use function strtolower;

/**
 * Sends through Postfix, which delivers to Dovecot over LMTP, and reads the
 * message back over IMAP: what a real mail system does to a message on its way.
 */
#[CoversClass(Smtp::class)]
#[Group('integration')]
final class PostfixEndToEndTest extends TestCase
{
    private const string FROM = 'sender@example.org';

    private const string TO = 'inbox@example.org';

    private RecordingConnection $connection;

    private Smtp $transport;

    protected function setUp(): void
    {
        Servers::skipUnlessRunning();

        $this->connection = new RecordingConnection();
        $this->transport  = new Smtp();
        $this->transport->setConnection(new Protocol\Smtp(
            new ConnectionConfig(Servers::HOST, Servers::SUBMISSION),
            authenticator: new Plain(Servers::USER, Servers::PASSWORD),
            connection: $this->connection,
        ));
    }

    private static function subject(string $prefix): string
    {
        return $prefix . ' ' . bin2hex(random_bytes(4));
    }

    private function sendAndReadBack(Message $message): Storage\Message
    {
        $this->transport->send($message);

        $received = Mailbox::delivered('inbox', (string) $message->getSubject());
        static::assertNotNull($received, 'The message was not delivered');

        return $received;
    }

    /**
     * The commands the client sent that start with $prefix.
     *
     * @return list<string>
     */
    private function sent(string $prefix): array
    {
        return array_values(array_filter(
            $this->connection->transcript(),
            static fn(string $line): bool => str_starts_with($line, "C: {$prefix}"),
        ));
    }

    #[Test]
    public function preservesUtf8HeadersAndDisplayNames(): void
    {
        $subject = self::subject(
            'Grüße aus Köln — “quoted”, 日本語のテキスト, and long enough to be folded more than once',
        );
        $received = $this->sendAndReadBack(
            (new Message())->setFrom(self::FROM, 'Jö Bloggs, Jr.')
                ->setTo(self::TO, 'Zoë "Zed" Ünal')
                ->setSubject($subject)
                ->setText('Hallo'),
        );

        static::assertSame(
            [$subject, 'Jö Bloggs, Jr.', 'Zoë "Zed" Ünal'],
            [
                $received->getSubject(),
                $received->getFrom()->first()?->getName(),
                $received->getTo()->first()?->getName(),
            ],
        );
    }

    #[Test]
    public function deliversToGroupMembersAndKeepsGroupInHeader(): void
    {
        $subject  = self::subject('Group');
        $received = $this->sendAndReadBack(
            (new Message())->setFrom(self::FROM)
                ->setTo(new AddressGroup('Team', AddressList::fromIterable([self::TO, 'member@example.org'])))
                ->setSubject($subject)
                ->setText('Hi team'),
        );

        static::assertSame(
            ['Team: inbox@example.org, member@example.org;', $subject],
            [
                (string) $received->getHeaders()->get('To')?->getFieldValue(),
                Mailbox::delivered('member', $subject)?->getSubject(),
            ],
        );
    }

    #[Test]
    public function preservesBinaryAttachmentAndUtf8FileName(): void
    {
        $bytes    = str_repeat(implode('', array_map(chr(...), range(
            start: 0,
            end: 255,
        ))), times: 64);
        $received = $this->sendAndReadBack(
            (new Message())->setFrom(self::FROM)
                ->setTo(self::TO)
                ->setSubject(self::subject('Attachment'))
                ->setText('See attached')
                ->attach(Attachment::fromString($bytes, 'Prüfbericht März.bin')),
        );

        static::assertSame(
            [2, 'Prüfbericht März.bin', $bytes],
            [$received->countParts(), $received->getPart(2)->getFilename(), $received->getPart(2)->getContent()],
        );
    }

    #[Test]
    public function preservesLinesLongerThanSmtpAllows(): void
    {
        $line     = str_repeat('0123456789', times: 120);
        $received = $this->sendAndReadBack(
            (new Message())->setFrom(self::FROM)
                ->setTo(self::TO)
                ->setSubject(self::subject(rtrim(str_repeat('word ', times: 60))))
                ->setText("{$line}\r\n.{$line}\r\n"),
        );

        static::assertSame("{$line}\r\n.{$line}", rtrim($received->getContent()));
    }

    /**
     * An 8-bit body is declared with BODY=8BITMIME, and arrives unchanged.
     */
    #[Test]
    public function deliversEightBitBodyDeclaredWith8BitMime(): void
    {
        $received = $this->sendAndReadBack(
            (new Message())->setFrom(self::FROM)
                ->setTo(self::TO)
                ->setSubject(self::subject('8bit'))
                ->setBody(new Part("Grüße, ½ €\r\n", 'text/plain', TransferEncoding::EightBit, 'UTF-8')),
        );

        static::assertSame(
            [true, '8bit', 'Grüße, ½ €'],
            [
                [] !== array_filter(
                    $this->sent('MAIL FROM:'),
                    static fn(string $line): bool => str_ends_with($line, ' BODY=8BITMIME'),
                ),
                strtolower((string) $received->getHeaders()->get('Content-Transfer-Encoding')?->getFieldValue()),
                rtrim($received->getContent()),
            ],
        );
    }

    /**
     * Postfix offers SMTPUTF8; the transaction for an address that is not ASCII must use it and be accepted.
     * Dovecot 2.3's LMTP does not take such an address, so the message is not read back.
     */
    #[Test]
    public function sendsToNonAsciiAddressWithSmtpUtf8(): void
    {
        $this->transport->send(
            (new Message())->setFrom(self::FROM)
                ->setTo('jörg@example.org')
                ->setSubject(self::subject('SMTPUTF8'))
                ->setText('Hallo'),
        );

        static::assertSame(
            [true, ['C: RCPT TO:<jörg@example.org>']],
            [str_ends_with($this->sent('MAIL FROM:')[0] ?? '', ' SMTPUTF8'), $this->sent('RCPT TO:')],
            implode("\n", $this->connection->transcript()),
        );
    }

    /**
     * Postfix announces SIZE 1048576; a larger message is refused before any of it is sent.
     */
    #[Test]
    public function refusesMessageLargerThanServerAnnouncesBeforeSendingIt(): void
    {
        $this->expectException(Protocol\Exception\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^The message is \d+ bytes; the server accepts at most 1048576$/');

        $this->transport->send(
            (new Message())->setFrom(self::FROM)
                ->setTo(self::TO)
                ->setSubject('Too large')
                ->setText('See attached')
                ->attach(Attachment::fromString(random_bytes(Servers::SUBMISSION_SIZE_LIMIT), 'large.bin')),
        );
    }
}
