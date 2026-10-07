<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp\Auth;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use SensitiveParameter;

use function preg_match;

/**
 * Checks shared by the authenticators. Error messages never repeat a secret.
 *
 * @internal
 */
final readonly class Credentials
{
    /** Shown in place of a secret by var_dump() and print_r() */
    public const string HIDDEN = '[hidden]';

    /**
     * @throws InvalidArgumentException When the username is empty or contains a control character.
     */
    public static function username(string $mechanism, string $username): string
    {
        if ('' === $username) {
            throw new InvalidArgumentException("{$mechanism} authentication requires a username");
        }

        if (1 === preg_match('/[\x00-\x1F\x7F]/', $username)) {
            throw new InvalidArgumentException("The {$mechanism} username must not contain control characters");
        }

        return $username;
    }

    /**
     * @throws InvalidArgumentException When the secret is empty.
     */
    public static function secret(string $mechanism, string $name, #[SensitiveParameter] string $secret): string
    {
        if ('' === $secret) {
            throw new InvalidArgumentException("{$mechanism} authentication requires {$name}");
        }

        return $secret;
    }
}
