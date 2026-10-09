<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset;

use Contenir\Mail\Header\HeaderInterface;
use Override;

/**
 * A custom header whose output contains a line break that is not folding, as a careless
 * HeaderInterface implementation might produce.
 */
final readonly class InjectingHeader implements HeaderInterface
{
    public function __construct(
        private string $line = "X-Custom: value\r\nBcc: hidden@example.com",
    ) {}

    #[Override]
    public static function fromString(string $headerLine): static
    {
        return new static($headerLine);
    }

    #[Override]
    public function getFieldName(): string
    {
        return 'X-Custom';
    }

    #[Override]
    public function getFieldValue(): string
    {
        return $this->line;
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        return $this->line;
    }

    #[Override]
    public function toString(): string
    {
        return $this->line;
    }
}
