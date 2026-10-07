<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

use Contenir\Mail\Protocol\Smtp\Auth\ChannelInterface;
use Override;
use SensitiveParameter;

use function array_shift;

/**
 * A ChannelInterface that records each step of an AUTH exchange and answers from a script.
 */
final class ScriptedChannel implements ChannelInterface
{
    /** @var list<array{line: string, expect: int, secret: bool}> */
    private array $steps = [];

    /** @var list<string> */
    private array $replies;

    /**
     * @param string ...$replies The reply text for each step, in order; "" when they run out.
     */
    public function __construct(string ...$replies)
    {
        $this->replies = $replies;
    }

    /**
     * @return list<array{line: string, expect: int, secret: bool}>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    #[Override]
    public function exchange(string $line, int $expect): string
    {
        $this->steps[] = ['line' => $line, 'expect' => $expect, 'secret' => false];

        return array_shift($this->replies) ?? '';
    }

    #[Override]
    public function exchangeSecret(#[SensitiveParameter] string $line, int $expect): string
    {
        $this->steps[] = ['line' => $line, 'expect' => $expect, 'secret' => true];

        return array_shift($this->replies) ?? '';
    }
}
