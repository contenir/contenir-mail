<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset;

use Contenir\Mail\Message;
use Contenir\Mail\Transport\Exception\RuntimeException;
use Contenir\Mail\Transport\TransportInterface;
use Override;

/**
 * A transport whose sends always fail with the given reason, for failover tests.
 */
final class FailingTransport implements TransportInterface
{
    public int $attempts = 0;

    public function __construct(
        private readonly string $reason = 'server unavailable',
    ) {}

    #[Override]
    public function send(Message $message): void
    {
        ++$this->attempts;

        throw new RuntimeException($this->reason);
    }
}
