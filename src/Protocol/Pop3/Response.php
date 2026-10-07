<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Pop3;

/**
 * POP3 response value object
 *
 * @internal
 */
final readonly class Response
{
    public function __construct(
        private string $status,
        private string $message,
    ) {}

    public function status(): string
    {
        return $this->status;
    }

    public function message(): string
    {
        return $this->message;
    }
}
