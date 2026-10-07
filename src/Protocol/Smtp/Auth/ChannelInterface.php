<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp\Auth;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use SensitiveParameter;

/**
 * The line-by-line conversation an authenticator holds with the SMTP server.
 *
 * @api
 */
interface ChannelInterface
{
    /**
     * Send one line and read the server's reply.
     *
     * @param string $line A command such as "AUTH LOGIN", or a response to a challenge.
     * @param int $expect The reply code that means the step succeeded, such as 334 or 235.
     * @return string The reply text after the code; for a 334 reply, the base64 challenge.
     * @throws InvalidArgumentException When the line contains CR, LF or NUL.
     * @throws RuntimeException When the server replies with a different code.
     */
    public function exchange(string $line, int $expect): string;

    /**
     * As exchange(), for a line that carries credentials: it is kept out of the session log.
     *
     * @throws InvalidArgumentException When the line contains CR, LF or NUL.
     * @throws RuntimeException When the server replies with a different code.
     */
    public function exchangeSecret(#[SensitiveParameter] string $line, int $expect): string;
}
