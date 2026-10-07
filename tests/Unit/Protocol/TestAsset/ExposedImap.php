<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\TestAsset;

use Contenir\Mail\Protocol\Imap;

/**
 * Exposes the protected parsing API that subclasses of Protocol\Imap build on.
 */
final class ExposedImap extends Imap
{
    public function line(): string
    {
        return $this->nextLine();
    }

    public function lineStartsWith(string $start): bool
    {
        return $this->assumedNextLine($start);
    }

    /**
     * @return array{string|null, string}
     */
    public function taggedLine(): array
    {
        $tag  = null;
        $line = $this->nextTaggedLine($tag);

        return [$tag, $line];
    }

    /**
     * @return array<mixed>
     */
    public function decode(string $line): array
    {
        return $this->decodeLine($line);
    }
}
