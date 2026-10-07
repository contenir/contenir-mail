<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use function is_string;
use function strtolower;

/**
 * How a connection to a mail server is secured.
 */
enum Security: string
{
    /** A plain connection, never upgraded */
    case None = 'none';

    /** TLS from the first byte, as on ports 465, 993 and 995 */
    case Tls = 'tls';

    /** A plain connection upgraded with STARTTLS (SMTP, IMAP) or STLS (POP3) */
    case StartTls = 'starttls';

    /**
     * Read the laminas-mail "ssl" setting: "ssl" was TLS from the start and
     * "tls" was STARTTLS.
     *
     * @internal For the positional protocol constructors kept from laminas-mail.
     */
    public static function fromLegacy(string|bool|null $ssl): self
    {
        return match (is_string($ssl) ? strtolower($ssl) : $ssl) {
            'ssl'   => self::Tls,
            'tls'   => self::StartTls,
            default => self::None,
        };
    }
}
