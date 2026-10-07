<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp\Auth;

use Contenir\Mail\Protocol\Exception\ExceptionInterface;

/**
 * An SMTP AUTH mechanism (RFC 4954), run by Protocol\Smtp after EHLO and any STARTTLS.
 *
 * Implement it to add a mechanism; the built-in ones are Plain, Login, CramMd5 and XOAuth2.
 *
 * @api
 */
interface AuthenticatorInterface
{
    /**
     * The SASL mechanism name the server must advertise in its EHLO AUTH line, such as "PLAIN".
     */
    public function mechanism(): string;

    /**
     * Run the AUTH exchange; return once the server has accepted the credentials.
     *
     * @throws ExceptionInterface When the server rejects the exchange.
     */
    public function authenticate(ChannelInterface $channel): void;
}
