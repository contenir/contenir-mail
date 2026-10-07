<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use Contenir\Mail\Exception\InvalidArgumentException;

use function is_string;
use function sprintf;
use function strtolower;
use function var_export;

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
     * "tls" was STARTTLS. An omitted setting (null) takes the default,
     * STARTTLS; false, "" and "none" ask for a plain connection explicitly.
     *
     * @internal For the positional protocol constructors kept from laminas-mail.
     * @throws InvalidArgumentException For any other value, rather than silently connecting without TLS.
     */
    public static function fromLegacy(string|bool|null $ssl): self
    {
        return match (is_string($ssl) ? strtolower($ssl) : $ssl) {
            'ssl'                   => self::Tls,
            'tls', 'starttls', null => self::StartTls,
            false, '', 'none'       => self::None,
            default                 => throw new InvalidArgumentException(sprintf(
                'Unknown connection security %s; expected "ssl", "tls", "none" or false',
                var_export($ssl, return: true),
            )),
        };
    }
}
