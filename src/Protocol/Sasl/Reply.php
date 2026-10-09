<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Sasl;

use Contenir\Mail\Protocol\Exception\RuntimeException;

/**
 * What the server answered a step of a SASL exchange with: a challenge, acceptance or refusal.
 *
 * @internal Each protocol reads its own reply syntax into one, for Authentication.
 */
final readonly class Reply
{
    private function __construct(
        public ?string $challenge,
        public ?RuntimeException $refusal,
    ) {}

    /**
     * @param string $challenge In base64, as the server sent it.
     */
    public static function challenge(string $challenge): self
    {
        return new self($challenge, null);
    }

    public static function accepted(): self
    {
        return new self(null, null);
    }

    /**
     * @param RuntimeException $refusal Its message is the reason the server gave, safe to display, and
     *     its code the protocol's reply code, where it has one, as SMTP does.
     */
    public static function refused(RuntimeException $refusal): self
    {
        return new self(null, $refusal);
    }
}
