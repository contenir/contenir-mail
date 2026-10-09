<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

use function array_column;
use function implode;

/**
 * A PSR-3 logger that keeps every record, for tests to read back.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    private array $records = [];

    /**
     * @param array<mixed> $context
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_column($this->records, 'message');
    }

    /**
     * Every message, one per line.
     */
    public function text(): string
    {
        return implode("\n", $this->messages());
    }
}
