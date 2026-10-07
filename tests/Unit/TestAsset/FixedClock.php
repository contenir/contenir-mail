<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * A clock stopped at one moment, so dates in messages are predictable.
 */
final readonly class FixedClock implements ClockInterface
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {}

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
