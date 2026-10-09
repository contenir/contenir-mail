<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;
use Deprecated;

use function is_string;
use function sprintf;

/**
 * Reads the connection settings of Storage\ImapConfig, Storage\Pop3Config and
 * Transport\SmtpConfig, with the laminas-mail "ssl" and "novalidatecert" keys
 * where the config accepts them. It lives with ConnectionConfig so that neither
 * Storage nor Transport depends on the other for it.
 *
 * @internal
 */
final class ConnectionSettings
{
    /** Connection keys besides ConnectionConfig::KEYS, kept from laminas-mail */
    public const array LEGACY_KEYS = ['ssl', 'novalidatecert'];

    /**
     * Security is STARTTLS unless "security" or "ssl" says otherwise. "ssl"
     * keeps its laminas-mail meaning: "SSL" is TLS from the start, "TLS" is
     * STARTTLS, and false is a plain connection.
     *
     * @throws InvalidArgumentException When a value has the wrong type or is unknown, or a setting is given under both its names.
     *
     * @mago-expect analysis:deprecated-method Reached only for the laminas-mail keys, so that PHP 8.4 and later report their use.
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
            verifyPeer: $reader->has('novalidatecert') ? self::legacyVerifyPeer($reader) : $connection->verifyPeer,
            timeout: $connection->timeout,
            tls: $connection->tls,
            logger: $connection->logger,
        );
    }

    /**
     * The laminas-mail "ssl" setting, read by Security::fromLegacy(): "ssl" is
     * TLS from the start, "tls" and "starttls" are STARTTLS, and false, "" and
     * "none" a plain connection.
     *
     * @throws InvalidArgumentException When the value is anything else.
     * @deprecated since 0.3.0, the "ssl" setting; use "security".
     */
    #[Deprecated('use "security" instead of the laminas-mail "ssl" setting', since: '0.3.0')]
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
     * The laminas-mail "novalidatecert" setting: true turns peer verification off.
     *
     * @throws InvalidArgumentException When the value is not a bool.
     * @deprecated since 0.3.0, the "novalidatecert" setting; use "verify_peer".
     */
    #[Deprecated('use "verify_peer" instead of the laminas-mail "novalidatecert" setting', since: '0.3.0')]
    private static function legacyVerifyPeer(ConfigReader $reader): bool
    {
        return ! $reader->bool('novalidatecert', default: false);
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
