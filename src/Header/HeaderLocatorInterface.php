<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

/**
 * Maps header names to the classes that parse them.
 *
 * @api
 */
interface HeaderLocatorInterface
{
    /**
     * @return class-string<HeaderInterface>|null
     */
    public function get(string $name): ?string;

    public function has(string $name): bool;
}
