<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use Contenir\Mail\Exception\InvalidArgumentException as MailInvalidArgumentException;
use Deprecated;

/**
 * Reads the laminas-mail positional connection arguments of Protocol\Imap and Protocol\Pop3.
 *
 * @internal
 */
final class LegacyOptions
{
    /**
     * The connection settings for a host, port and "ssl" argument.
     *
     * Called only when a caller passes them, so PHP 8.4 and later report that use.
     *
     * @throws Exception\InvalidArgumentException When $ssl is not a recognised setting.
     * @throws MailInvalidArgumentException When the port is out of range.
     * @deprecated since 0.3.0, the laminas-mail host, port and "ssl" arguments of Protocol\Imap and
     *     Protocol\Pop3; pass a ConnectionConfig.
     */
    #[Deprecated(
        'pass a ConnectionConfig to Protocol\\Imap or Protocol\\Pop3 instead of a host, port and "ssl"',
        since: '0.3.0',
    )]
    public static function config(
        string $host,
        ?int $port,
        string|bool|Security|null $ssl,
        bool $verifyPeer,
        int $timeout,
    ): ConnectionConfig {
        return new ConnectionConfig(
            host: $host,
            port: 0 === $port ? null : $port,
            security: self::security($ssl),
            verifyPeer: $verifyPeer,
            timeout: $timeout,
        );
    }

    /**
     * The security an "ssl" argument asks for, read by Security::fromLegacy().
     *
     * Null means the default, STARTTLS. As in laminas-mail, "ssl" is TLS
     * from the start, "tls" is STARTTLS, and false is a plain connection.
     * "starttls" and "none" are accepted too. Anything else is rejected
     * rather than silently connecting in plain text.
     *
     * @throws Exception\InvalidArgumentException When $ssl is true or an unknown string.
     */
    public static function security(string|bool|Security|null $ssl): Security
    {
        if ($ssl instanceof Security) {
            return $ssl;
        }

        try {
            return Security::fromLegacy($ssl);
        } catch (MailInvalidArgumentException $e) {
            throw new Exception\InvalidArgumentException($e->getMessage(), previous: $e);
        }
    }
}
