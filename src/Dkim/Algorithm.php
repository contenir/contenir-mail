<?php

declare(strict_types=1);

namespace Contenir\Mail\Dkim;

use Contenir\Mail\Dkim\Exception\InvalidArgumentException;

use function addcslashes;
use function sprintf;
use function strtolower;

/**
 * The DKIM signing algorithms, as written in the a= tag.
 *
 * rsa-sha1 is not offered: RFC 8301 forbids signing with it, and verifiers
 * treat such signatures as failing.
 */
enum Algorithm: string
{
    /** RSA with SHA-256 (RFC 6376, section 3.3.1) */
    case RsaSha256 = 'rsa-sha256';

    /** Ed25519 over a SHA-256 hash (RFC 8463) */
    case Ed25519Sha256 = 'ed25519-sha256';

    /**
     * The algorithm with this name, in any case.
     *
     * @throws InvalidArgumentException When the name is rsa-sha1 or not a DKIM algorithm.
     */
    public static function fromName(string $name): self
    {
        $lower = strtolower($name);
        if ('rsa-sha1' === $lower) {
            throw new InvalidArgumentException(
                'DKIM algorithm rsa-sha1 is refused, as RFC 8301 forbids signing with SHA-1; '
                    . 'use rsa-sha256 or ed25519-sha256',
            );
        }

        return (
            self::tryFrom($lower) ?? throw new InvalidArgumentException(sprintf(
                'Unknown DKIM algorithm "%s"; expected rsa-sha256 or ed25519-sha256',
                addcslashes($name, characters: "\0..\37\177"),
            ))
        );
    }
}
