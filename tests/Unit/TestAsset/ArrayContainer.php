<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

use Override;
use Psr\Container\ContainerInterface;
use RuntimeException;

use function array_key_exists;

/**
 * A PSR-11 container holding fixed services, for testing factories.
 */
final readonly class ArrayContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $services
     */
    public function __construct(
        private array $services = [],
    ) {}

    /**
     * @throws RuntimeException When there is no such service.
     */
    #[Override]
    public function get(string $id): mixed
    {
        if (! $this->has($id)) {
            throw new RuntimeException("No service {$id}");
        }

        return $this->services[$id];
    }

    #[Override]
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}
