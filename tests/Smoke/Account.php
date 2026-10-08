<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Smoke;

use RuntimeException;
use SensitiveParameter;

use function getenv;
use function sprintf;

/**
 * Your account, read from SMOKE_* environment variables and never printed.
 *
 * @mago-expect lint:excessive-parameter-list Built from the environment; every credential but the user is optional.
 */
final readonly class Account
{
    public function __construct(
        public Provider $provider,
        public string $user,
        #[SensitiveParameter]
        public ?string $password = null,
        #[SensitiveParameter]
        public ?string $token = null,
        public ?string $microsoftClientId = null,
        public ?string $to = null,
    ) {}

    /**
     * @throws RuntimeException When SMOKE_PROVIDER or SMOKE_USER is missing or unknown.
     */
    public static function fromEnvironment(): self
    {
        $provider = Provider::tryFrom(self::env('SMOKE_PROVIDER') ?? '');
        $user     = self::env('SMOKE_USER');
        if (null === $provider || null === $user) {
            throw new RuntimeException(sprintf(
                'Set SMOKE_PROVIDER (gmail, outlook, office365 or custom) and SMOKE_USER; see %s',
                'tests/Smoke/README.md',
            ));
        }

        return new self(
            $provider,
            $user,
            self::env('SMOKE_PASSWORD'),
            self::env('SMOKE_TOKEN'),
            self::env('SMOKE_MS_CLIENT_ID'),
            self::env('SMOKE_TO'),
        );
    }

    public function recipient(): string
    {
        return $this->to ?? $this->user;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? null : $value;
    }
}
