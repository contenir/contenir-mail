<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Security;

use function is_string;
use function sprintf;

/**
 * Reads the connection settings of ImapConfig, Pop3Config and Transport\SmtpConfig,
 * with the laminas-mail "ssl" and "novalidatecert" keys where the config accepts them.
 *
 * @internal
 */
final class RemoteConnection
{
    /** Connection keys besides ConnectionConfig::KEYS, kept from laminas-mail */
    public const array LEGACY_KEYS = ['ssl', 'novalidatecert'];

    /**
     * Security is STARTTLS unless "security" or "ssl" says otherwise. "ssl"
     * keeps its laminas-mail meaning: "SSL" is TLS from the start, "TLS" is
     * STARTTLS, and false is a plain connection.
     *
     * @throws InvalidArgumentException When a value has the wrong type or is unknown, or a setting is given under both its names.
     */
    public static function fromReader(ConfigReader $reader, string $context): ConnectionConfig
    {
        $connection = ConnectionConfig::fromReader($reader);
        self::exclusive($reader, $context, 'security', 'ssl');
        self::exclusive($reader, $context, 'verify_peer', 'novalidatecert');

        return new ConnectionConfig(
            host: $connection->host,
            port: $connection->port,
            security: match (true) {
                $reader->has('ssl') => self::legacySecurity($reader, $context),
                $reader->has('security') => $connection->security,
                default => Security::StartTls,
            },
            verifyPeer: ! $reader->bool('novalidatecert', default: ! $connection->verifyPeer),
            timeout: $connection->timeout,
            tls: $connection->tls,
        );
    }

    /**
     * The laminas-mail "ssl" setting, read by Security::fromLegacy(): "ssl" is
     * TLS from the start, "tls" and "starttls" are STARTTLS, and false, "" and
     * "none" a plain connection.
     *
     * @throws InvalidArgumentException When the value is anything else.
     */
    private static function legacySecurity(ConfigReader $reader, string $context): Security
    {
        $ssl = $reader->stringOrBool('ssl');

        try {
            return Security::fromLegacy($ssl);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(
                sprintf(
                    '%s: option "ssl" must be "ssl", "tls", "starttls", "none" or false, got "%s"',
                    $context,
                    is_string($ssl) ? $ssl : 'true',
                ),
                previous: $e,
            );
        }
    }

    /**
     * @throws InvalidArgumentException When both keys are given.
     */
    private static function exclusive(ConfigReader $reader, string $context, string $key, string $legacyKey): void
    {
        if ($reader->has($key) && $reader->has($legacyKey)) {
            throw new InvalidArgumentException(sprintf(
                '%s: give option "%s" or its laminas-mail form "%s", not both',
                $context,
                $key,
                $legacyKey,
            ));
        }
    }
}
