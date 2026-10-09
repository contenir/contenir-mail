<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use SensitiveParameter;

use function preg_match;

/**
 * Keeps credentials out of what is logged: the session log of AbstractProtocol, and
 * what LoggingConnection writes to a PSR-3 logger.
 *
 * @internal
 */
final class Redaction
{
    /**
     * Commands whose arguments are credentials
     */
    private const string CREDENTIAL_COMMAND =
        '/^(?<prefix>(?:\S+ +)??)(?<command>LOGIN|AUTHENTICATE|AUTH|USER|PASS|APOP)'
            . '(?<mechanism>(?<=AUTH|AUTHENTICATE) +\S+)?(?<secret> .*)?$/isD';

    /**
     * The command line as it may be logged: the arguments of LOGIN, AUTHENTICATE, AUTH, USER,
     * PASS and APOP replaced by "[redacted]", after any tag and SASL mechanism name.
     */
    public static function redact(#[SensitiveParameter] string $line): string
    {
        if (1 !== preg_match(self::CREDENTIAL_COMMAND, $line, $matches) || '' === ($matches['secret'] ?? '')) {
            return $line;
        }

        return (
            ($matches['prefix'] ?? '')
                . ($matches['command'] ?? '')
                . ($matches['mechanism'] ?? '')
                . ' '
                . AbstractProtocol::REDACTED
        );
    }

    /**
     * Send bytes that carry credentials, with writeSecret() when the connection records what it sends.
     *
     * @throws Exception\RuntimeException When the connection is closed or the bytes cannot be sent.
     */
    public static function writeSecret(ConnectionInterface $connection, #[SensitiveParameter] string $data): void
    {
        if ($connection instanceof RedactingConnectionInterface) {
            $connection->writeSecret($data);

            return;
        }

        $connection->write($data);
    }
}
