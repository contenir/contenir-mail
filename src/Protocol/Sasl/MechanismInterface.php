<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Sasl;

use Contenir\Mail\Protocol\Exception\ExceptionInterface;

/**
 * A SASL mechanism, as the client (RFC 4422): the credentials, and how to prove them.
 *
 * `Protocol\Imap::authenticate()` and `Protocol\Pop3::authenticate()` take one, and
 * `Protocol\Smtp\Auth\SaslAuthenticator` runs one over SMTP AUTH, so a mechanism is
 * written once for all three protocols. The built-in ones are Xoauth2 and ScramSha256,
 * which are SMTP authenticators too.
 *
 * The mechanism holds the settings and may be used for many sign-ins; each sign-in
 * starts an exchange of its own, which holds that attempt's state.
 *
 * @api
 */
interface MechanismInterface
{
    /**
     * The mechanism's name as IANA registers it, in upper case, such as "XOAUTH2".
     * The server must advertise it, as AUTH=… in IMAP, in POP3's SASL capability,
     * or in the SMTP EHLO AUTH line.
     */
    public function mechanism(): string;

    /**
     * Start a new exchange, before anything is sent to the server.
     *
     * @throws ExceptionInterface When the credentials cannot be prepared, such as when an
     *     access token provider fails; nothing has been sent.
     */
    public function start(): ExchangeInterface;
}
