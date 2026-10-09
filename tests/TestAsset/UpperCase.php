<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset;

use function strtoupper;

/**
 * An invokable object, the one kind of callable besides a Closure that settings may hold.
 */
final readonly class UpperCase
{
    public function __invoke(string $value = 'token'): string
    {
        return strtoupper($value);
    }
}
