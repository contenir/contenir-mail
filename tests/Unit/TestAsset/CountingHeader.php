<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

use Contenir\Mail\Header\HeaderInterface;
use Override;

/**
 * A header that counts how often its name is read, so a test can tell whether
 * a collection went over the headers it already holds.
 */
final class CountingHeader implements HeaderInterface
{
    private int $nameReads = 0;

    public function __construct(
        private readonly string $name,
        private readonly string $value = 'v',
    ) {}

    #[Override]
    public static function fromString(string $headerLine): static
    {
        return new static($headerLine);
    }

    #[Override]
    public function getFieldName(): string
    {
        $this->nameReads++;

        return $this->name;
    }

    #[Override]
    public function getFieldValue(): string
    {
        return $this->value;
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        return $this->value;
    }

    #[Override]
    public function toString(): string
    {
        return "{$this->name}: {$this->value}";
    }

    public function nameReads(): int
    {
        return $this->nameReads;
    }
}
