<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport;

use Contenir\Mail\Message;
use Override;

/**
 * Keeps the last message instead of sending it, for tests and development.
 */
final class InMemory implements TransportInterface
{
    private ?Message $lastMessage = null;

    #[Override]
    public function send(Message $message): void
    {
        $this->lastMessage = $message;
    }

    /**
     * The last message sent, or null before the first send.
     */
    public function getLastMessage(): ?Message
    {
        return $this->lastMessage;
    }
}
