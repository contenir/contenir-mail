<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use function is_string;
use function strtolower;

/**
 * What a folder is for, as an IMAP server marks it with a special-use
 * attribute (RFC 6154), such as the folder sent mail is kept in.
 *
 * @api
 */
enum SpecialUse: string
{
    /** Every message in the mailbox, as a virtual folder */
    case All = '\All';

    /** Messages kept out of the inbox */
    case Archive = '\Archive';

    /** Messages not finished */
    case Drafts = '\Drafts';

    /** Flagged messages, as a virtual folder */
    case Flagged = '\Flagged';

    /** Messages judged to be spam */
    case Junk = '\Junk';

    /** Copies of messages sent */
    case Sent = '\Sent';

    /** Messages to be removed */
    case Trash = '\Trash';

    /**
     * The case of the first special-use attribute among a folder's attributes,
     * matched without regard to case; null when there is none.
     *
     * @param iterable<mixed> $attributes
     *
     * @mago-expect analysis:mixed-assignment The protocol returns LIST attributes untyped; each is checked here.
     */
    public static function fromAttributes(iterable $attributes): ?self
    {
        foreach ($attributes as $attribute) {
            $use = is_string($attribute) ? self::fromAttribute($attribute) : null;
            if (null !== $use) {
                return $use;
            }
        }

        return null;
    }

    /**
     * The case an attribute names, matched without regard to case; null for any other attribute.
     */
    public static function fromAttribute(string $attribute): ?self
    {
        $lower = strtolower($attribute);
        foreach (self::cases() as $case) {
            if (strtolower($case->value) === $lower) {
                return $case;
            }
        }

        return null;
    }
}
