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
     * @param int $messages How many messages the folder holds.
     * @param int $unseen How many of them have not been seen.
     * @param int $uidNext The unique ID the next message will have, at least.
     * @param int|null $size The total size of its messages in octets; null when the server cannot say.
     */
    public function __construct(
        public int $messages,
        public int $unseen,
        public int $uidNext,
        public ?int $size,
    ) {}
}
