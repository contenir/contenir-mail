<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset;

use Contenir\Mail\Headers;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Mime\PartInterface;
use Override;

/**
 * A part with whatever headers a test gives it, such as none at all, which
 * Mime\Part and Mime\Multipart never produce.
 */
final readonly class PartWithHeaders implements PartInterface
{
    public function __construct(
        private Headers $headers,
        private bool $multipart = false,
    ) {}

    #[Override]
    public function getHeaders(): Headers
    {
        return $this->headers;
    }

    #[Override]
    public function isMultipart(): bool
    {
        return $this->multipart;
    }

    #[Override]
    public function getParts(): array
    {
        return $this->multipart ? [new Part('x')] : [];
    }

    #[Override]
    public function getContent(): string
    {
        return '';
    }

    #[Override]
    public function getEncodedContent(): string
    {
        return '';
    }
}
