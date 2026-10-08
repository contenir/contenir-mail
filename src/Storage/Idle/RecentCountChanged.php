<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Idle;

/**
 * $count messages in the folder are new to this session (RFC 3501, section 7.3.2).
 * IMAP4rev2 servers no longer send it.
 *
 * @api
 */
final readonly class RecentCountChanged implements EventInterface
{
    public function __construct(
        public int $count,
    ) {}
}
