<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Idle;

use Contenir\Mail\Storage\Flag;

/**
 * Message $number now has these flags, all of them, as another client changed them
 * (RFC 3501, section 7.4.2).
 *
 * @api
 */
final readonly class FlagsChanged implements EventInterface
{
    /**
     * @param list<Flag|string> $flags The flags with a case in Flag as that case, and keywords as strings.
     */
    public function __construct(
        public int $number,
        public array $flags,
    ) {}
}
