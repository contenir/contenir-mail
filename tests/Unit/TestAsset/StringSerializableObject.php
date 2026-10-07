<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

use Stringable;

class StringSerializableObject implements Stringable
{
    public function __construct(
        private string $message,
    ) {}

    public function __toString(): string
    {
        return $this->message;
    }
}
