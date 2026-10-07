<?php

namespace Contenir\Mail\Transport;

use Contenir\Mail\Message;
use Override;

/**
 * InMemory transport
 *
 * This transport will just store the message in memory.  It is helpful
 * when unit testing, or to prevent sending email when in development or
 * testing.
 */
class InMemory implements TransportInterface
{
    /** @var null|Message */
    protected $lastMessage;

    /**
     * Takes the last message and saves it for testing.
     */
    #[Override]
    public function send(Message $message)
    {
        $this->lastMessage = $message;
    }

    /**
     * Get the last message sent.
     *
     * @return null|Message
     */
    public function getLastMessage()
    {
        return $this->lastMessage;
    }
}
