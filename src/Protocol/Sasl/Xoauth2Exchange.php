<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Sasl;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Smtp\Auth\Credentials;
use Contenir\Mail\Protocol\Xoauth2\Xoauth2 as Encoder;
use Override;
use SensitiveParameter;

/**
 * One XOAUTH2 sign-in: the token goes in the initial response, and the only challenge
 * a server sends is its refusal, as base64 JSON, which is answered with an empty response
 * before the server's reason follows (RFC 7628, section 3.2.3).
 *
 * @internal Xoauth2 starts it.
 */
final class Xoauth2Exchange implements ExchangeInterface
{
    /** The refusal the server sent as a challenge, in base64 */
    private ?string $refusal = null;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $response,
    ) {}

    #[Override]
    public function initialResponse(): string
    {
        return $this->response;
    }

    /**
     * @throws RuntimeException When the server sends a second challenge, which XOAUTH2 has no answer to.
     */
    #[Override]
    public function respond(string $challenge): string
    {
        if (null !== $this->refusal) {
            throw new RuntimeException('The server sent XOAUTH2 a second challenge');
        }

        $this->refusal = $challenge;

        return '';
    }

    /**
     * @throws RuntimeException When the server refused the token in a challenge, whatever it says after.
     */
    #[Override]
    public function complete(): void
    {
        if (null !== $this->refusal) {
            throw new RuntimeException(Encoder::refusal($this->refusal));
        }
    }

    #[Override]
    public function refusal(string $reason): string
    {
        return match (true) {
            null !== $this->refusal => Encoder::refusal($this->refusal, $reason),
            '' === $reason => 'The server refused the access token',
            default => $reason,
        };
    }

    /**
     * @return array{response: string}
     */
    public function __debugInfo(): array
    {
        return ['response' => Credentials::HIDDEN];
    }
}
