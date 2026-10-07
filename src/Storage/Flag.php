<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use function strtolower;

/**
 * The message flags Maildir and IMAP have in common, by their IMAP names.
 *
 * Flags without a case here, such as IMAP keywords ("$Junk") or Maildir
 * keyword letters, stay strings wherever flags are read or written.
 *
 * @api
 */
enum Flag: string
{
    /** Read */
    case Seen = '\Seen';

    /** Replied to */
    case Answered = '\Answered';

    /** Marked for attention */
    case Flagged = '\Flagged';

    /** Marked for removal */
    case Deleted = '\Deleted';

    /** Not finished */
    case Draft = '\Draft';

    /** New since the mailbox was last opened; set by the storage, never by the client */
    case Recent = '\Recent';

    /** Forwarded, resent or bounced: Maildir "P", the IMAP keyword "$Forwarded" */
    case Passed = '$Forwarded';

    /** Spellings from IMAP and laminas-mail that name a case, lower-cased */
    private const array ALIASES = [
        '\passed' => '$Forwarded',
        'passed'  => '$Forwarded',
    ];

    /** Maildir info letters (Maildir "2," info) by case value */
    private const array MAILDIR = [
        '\Draft'     => 'D',
        '\Flagged'   => 'F',
        '$Forwarded' => 'P',
        '\Answered'  => 'R',
        '\Seen'      => 'S',
        '\Deleted'   => 'T',
    ];

    /**
     * The case an IMAP flag names, matched without regard to case, or the flag itself when it is a keyword.
     */
    public static function fromImap(string $flag): self|string
    {
        $lower = strtolower($flag);
        foreach (self::cases() as $case) {
            if (strtolower($case->value) === $lower) {
                return $case;
            }
        }

        $alias = self::ALIASES[$lower] ?? null;

        return null === $alias ? $flag : self::from($alias);
    }

    /**
     * A flag given as a case or as its IMAP name, as a case where there is one.
     */
    public static function normalise(self|string $flag): self|string
    {
        return $flag instanceof self ? $flag : self::fromImap($flag);
    }

    /**
     * The case a Maildir info letter stands for, or the letter itself, a keyword, when none does.
     */
    public static function fromMaildir(string $letter): self|string
    {
        foreach (self::MAILDIR as $value => $maildirLetter) {
            if ($maildirLetter === $letter) {
                return self::from($value);
            }
        }

        return $letter;
    }

    /**
     * The Maildir info letter, or null for Recent, which Maildir shows by keeping the file in new/.
     */
    public function maildirLetter(): ?string
    {
        return self::MAILDIR[$this->value] ?? null;
    }
}
