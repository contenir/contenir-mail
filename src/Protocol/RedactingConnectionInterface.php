<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use SensitiveParameter;

/**
 * A connection that records what it sends, as LoggingConnection does, and so must be
 * told which bytes carry credentials.
 *
 * IMAP, POP3 and SMTP send passwords, access tokens, APOP digests and every SASL
 * response with writeSecret() when the connection offers it, and with write()
 * otherwise. A decorator that wraps such a connection passes writeSecret() on.
 *
 * @api
 */
interface RedactingConnectionInterface extends ConnectionInterface
{
    /**
     * Send the bytes as write() does. They carry credentials, so record them only as
     * AbstractProtocol::REDACTED, or as the command they start, its arguments redacted.
     *
     * @throws Exception\RuntimeException When the connection is closed or the bytes cannot be sent.
     */
    public function writeSecret(#[SensitiveParameter] string $data): void;
}
