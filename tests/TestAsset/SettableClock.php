<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * A clock that moves only when told to.
 */
final class SettableClock implements ClockInterface
{
    public function __construct(
        private DateTimeImmutable $now = new DateTimeImmutable('2026-01-01 00:00:00'),
    ) {}

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
