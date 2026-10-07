<?php

declare(strict_types=1);

namespace Contenir\Mail;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * The system time, for messages created without a clock of their own.
 *
 * @internal
 */
final readonly class SystemClock implements ClockInterface
{
    #[Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
