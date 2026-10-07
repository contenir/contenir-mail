<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use Contenir\Mail\Exception\InvalidArgumentException as MailInvalidArgumentException;

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
     * @throws Exception\InvalidArgumentException When $ssl is not a recognised setting.
     * @throws MailInvalidArgumentException When the port is out of range.
     */
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
