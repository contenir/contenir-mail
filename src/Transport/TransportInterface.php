<?php

namespace Contenir\Mail\Transport;

use Contenir\Mail;

/**
 * Interface for mail transports
 */
interface TransportInterface
{
    /**
     * Send a mail message
     *
     * @return void
     */
    public function send(Mail\Message $message);
}
