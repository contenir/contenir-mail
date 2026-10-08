<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Idle;

/**
 * Message $number was removed, and the messages after it are now numbered one lower
 * (RFC 3501, section 7.4.1).
 *
 * @api
 */
final readonly class MessageExpunged implements EventInterface
{
    public function __construct(
        public int $number,
    ) {}
}
