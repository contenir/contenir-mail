<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp\Auth;

use Closure;
use Override;
use SensitiveParameter;

/**
 * The Channel Protocol\Smtp hands to an authenticator, so its own exchange methods can stay private.
 *
 * @internal
 */
final readonly class CallbackChannel implements ChannelInterface
{
    /**
     * @param Closure(string, int): string $exchange
     * @param Closure(string, int): string $exchangeSecret
     */
    public function __construct(
        private Closure $exchange,
        #[SensitiveParameter]
        private Closure $exchangeSecret,
    ) {}

    #[Override]
    public function exchange(string $line, int $expect): string
    {
        return ($this->exchange)($line, $expect);
    }

    #[Override]
    public function exchangeSecret(#[SensitiveParameter] string $line, int $expect): string
    {
        return ($this->exchangeSecret)($line, $expect);
    }
}
