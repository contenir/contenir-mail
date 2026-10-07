<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport;

use Contenir\Mail;

/**
 * Sends a message: through SMTP, sendmail, to a file, or into memory.
 *
 * @api
 */
interface TransportInterface
{
    /**
     * @throws Mail\Exception\ExceptionInterface When the message cannot be written or sent.
     */
    public function send(Mail\Message $message): void;
}
