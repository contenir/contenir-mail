<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\TestAsset;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Storage;
use Contenir\Mail\Storage\ImapConfig;

use function usleep;

/**
 * Reads what Postfix delivered to the first Dovecot, which takes any user
 * name with the one password.
 */
final class Mailbox
{
    /**
     * The message with this subject in $user's INBOX, waiting up to five seconds for Postfix to hand it over.
     */
    public static function delivered(string $user, string $subject): ?Storage\Message
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $mailbox = new Storage\Imap(new ImapConfig(
                new ConnectionConfig(Servers::HOST, Servers::IMAP),
                $user,
                Servers::PASSWORD,
            ));
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
}
