<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Sasl;

use Contenir\Mail\Protocol\Exception\RuntimeException;

/**
 * One sign-in with a SASL mechanism, as the client (RFC 4422, section 3).
 *
 * The protocol sends the initial response, then hands each server challenge to
 * respond() and sends back what it returns, until the server accepts or refuses.
 * Challenges and responses are base64, as IMAP, POP3 and SMTP carry them; the
 * protocol writes an empty initial response as "=" where its syntax needs one.
 * Every response is sent as a secret, kept out of logs.
 *
 * ```php
 * $exchange = $mechanism->start();
 * $reply    = $send($exchange->initialResponse());
 * while ($reply is a challenge) {
 *     $reply = $send($exchange->respond($challenge)); // cancelled with "*" when respond() throws
 * }
 * $reply is acceptance ? $exchange->complete() : throw new RuntimeException($exchange->refusal($reason));
 * ```
 *
 * @api
 */
interface ExchangeInterface
{
    /**
     * The response sent with the command, in base64; "" for an empty one, or null
     * when the mechanism has none and waits for the server's first challenge.
     */
    public function initialResponse(): ?string;

    /**
     * Answer a server challenge.
     *
     * @param string $challenge The challenge in base64, as the server sent it; "" when it sent none.
     * @return string The response in base64; "" for an empty one.
     * @throws RuntimeException When the challenge cannot be answered, such as a server proof that
     *     does not match; the protocol then cancels the exchange with "*" before passing it on.
     */
    public function respond(string $challenge): string;

    /**
     * Check the server's acceptance, after the last challenge.
     *
     * @throws RuntimeException When the mechanism cannot trust it, as SCRAM cannot when the server
     *     has not proved it knows the password. The server considers the client signed in, so the
     *     connection should be closed.
     */
    public function complete(): void;

    /**
     * The message to report when the server refuses the credentials.
     *
     * @param string $reason The reason the server gave, safe to display; "" when it gave none.
     */
    public function refusal(string $reason): string;
}
