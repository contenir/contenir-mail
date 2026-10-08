<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Idle;

/**
 * The folder now holds $count messages: when the count grows, new mail has arrived,
 * numbered up to $count (RFC 3501, section 7.3.1).
 *
 * @api
 */
final readonly class MessageCountChanged implements EventInterface
{
    public function __construct(
        public int $count,
    ) {}
}
