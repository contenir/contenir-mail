<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

/**
 * The status of an IMAP folder, read without selecting it.
 *
 * @api
 */
final readonly class FolderStatus
{
    /**
     * @param int $messageCount How many messages the folder holds.
     * @param int $unseenCount How many of them have not been seen.
     * @param int $uidNext The UID the next message will have, at least.
     * @param int $uidValidity The folder's UIDVALIDITY: a UID names the same message only while this
     *     stays the same, so store it with any UID kept for later.
     * @param int|null $size The total size of its messages in octets; null when the server cannot say.
     */
    public function __construct(
        public int $messageCount,
        public int $unseenCount,
        public int $uidNext,
        public int $uidValidity,
        public ?int $size,
    ) {}
}
