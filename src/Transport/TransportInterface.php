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
     * @throws Mail\Exception\ExceptionInterface When the message cannot be written or sent.
     * @return void
     */
    public function send(Mail\Message $message);
}
