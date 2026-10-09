<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration;

use Contenir\Mail\Message;
use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Storage;
use Contenir\Mail\Storage\AbstractStorage;
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\ImapConfig;
use Contenir\Mail\Storage\Pop3Config;
use Contenir\Mail\Tests\Integration\TestAsset\Servers;
use Contenir\Mail\Transport\Smtp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function random_bytes;
use function rtrim;
use function str_repeat;
use function usleep;

/**
 * GreenMail, a second implementation of SMTP, IMAP and POP3, as a second
 * opinion on what Dovecot and Postfix accept. It runs over TLS from the start.
 */
#[CoversClass(Smtp::class)]
#[CoversClass(Storage\Imap::class)]
#[CoversClass(Storage\Pop3::class)]
#[Group('integration')]
final class GreenMailTest extends TestCase
{
    protected function setUp(): void
    {
        Servers::skipUnlessRunning();
    }

    private static function connection(int $port): ConnectionConfig
    {
        return new ConnectionConfig(Servers::HOST, $port, Security::Tls);
    }

    private static function imap(): Storage\Imap
    {
        return new Storage\Imap(new ImapConfig(
            self::connection(Servers::GREENMAIL_IMAPS),
            Servers::USER,
            Servers::PASSWORD,
        ));
    }

    private static function pop3(): Storage\Pop3
    {
        return new Storage\Pop3(new Pop3Config(
            self::connection(Servers::GREENMAIL_POP3S),
            Servers::USER,
            Servers::PASSWORD,
        ));
    }

    /**
     * Send a message with a UTF-8 subject, a long line and an attachment, returning its subject.
     */
    private static function send(): string
    {
        $subject = 'Grüße ' . bin2hex(random_bytes(4));
        (new Smtp([
            'host'     => Servers::HOST,
            'port'     => Servers::GREENMAIL_SMTPS,
            'security' => 'tls',
            'auth'     => ['type' => 'plain', 'username' => Servers::USER, 'password' => Servers::PASSWORD],
        ]))->send(
            (new Message())->setFrom('sender@example.org', 'Jö Bloggs')
                ->setTo(Servers::USER . '@example.org')
                ->setSubject($subject)
                ->setText(str_repeat('0123456789', times: 120))
                ->attach(Attachment::fromString("%PDF-1.4\x00\xFF", 'Prüfbericht.pdf', 'application/pdf')),
        );

        return $subject;
    }

    /**
     * The message with this subject, waiting up to five seconds for it to arrive.
     *
     * @param callable(): AbstractStorage $open
     */
    private static function find(callable $open, string $subject): ?Storage\Message
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $mailbox = $open();
            foreach ($mailbox as $message) {
                if ($message->getSubject() === $subject) {
                    return $message;
                }
            }

            $mailbox->close();
            usleep(100_000);
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    private static function summary(?Storage\Message $message): array
    {
        return [
            $message?->getFrom()->first()?->getName(),
            rtrim((string) $message?->getPart(1)->getContent()),
            $message?->getPart(2)->getFilename(),
            $message?->getPart(2)->getContent(),
        ];
    }

    #[Test]
    public function sendsAndReadsBackOverImap(): void
    {
        $subject = self::send();

        static::assertSame(
            ['Jö Bloggs', str_repeat('0123456789', times: 120), 'Prüfbericht.pdf', "%PDF-1.4\x00\xFF"],
            self::summary(self::find(self::imap(...), $subject)),
        );
    }

    #[Test]
    public function sendsAndReadsBackOverPop3(): void
    {
        $subject = self::send();

        static::assertSame(
            ['Jö Bloggs', str_repeat('0123456789', times: 120), 'Prüfbericht.pdf', "%PDF-1.4\x00\xFF"],
            self::summary(self::find(self::pop3(...), $subject)),
        );
    }

    #[Test]
    public function managesFoldersFlagsAndMessagesOverImap(): void
    {
        $folder  = 'Archive ' . bin2hex(random_bytes(4));
        $subject = self::send();
        static::assertNotNull(self::find(self::imap(...), $subject), 'The message was not delivered');

        $imap = self::imap();
        $imap->createFolder($folder);
        $imap->appendMessage(
            (new Message())->setFrom('sender@example.org')
                ->setTo('test@example.org')
                ->setSubject('Appended')
                ->setText('Appended over IMAP')
                ->toString(),
            $folder,
        );

        $id = 0;
        foreach ($imap as $number => $message) {
            if ($message->getSubject() !== $subject) {
                continue;
            }

            $id = $number;
        }

        $imap->setFlags($id, [Flag::Seen, Flag::Flagged]);
        $flagged = $imap->getMessage($id)->hasFlag(Flag::Flagged);
        $imap->moveMessage($id, $folder);
        $imap->selectFolder($folder);

        static::assertSame([true, 2, 1], [$flagged, $imap->countMessages(), $imap->countMessages(Flag::Flagged)]);
    }
}
