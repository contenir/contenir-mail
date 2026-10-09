<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Smtp\Auth\ChannelInterface;
use Override;
use SensitiveParameter;

use function array_shift;
use function is_string;

/**
 * A ChannelInterface that records each step of an AUTH exchange and answers from a script.
 *
 * A reply given as a string is the text of the code the step expects. One given as
 * [code, text] is a reply with that code, thrown as the server's refusal would be
 * when the step expects another.
 */
final class ScriptedChannel implements ChannelInterface
{
    /** @var list<array{line: string, expect: int, secret: bool}> */
    private array $steps = [];

    /** @var list<string|array{int, string}> */
    private array $replies;

    /**
     * @param string|array{int, string} ...$replies The reply for each step, in order; "" when they run out.
     */
    public function __construct(string|array ...$replies)
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

        return $this->reply($expect);
    }

    #[Override]
    public function exchangeSecret(#[SensitiveParameter] string $line, int $expect): string
    {
        $this->steps[] = ['line' => $line, 'expect' => $expect, 'secret' => true];

        return $this->reply($expect);
    }

    private function reply(int $expect): string
    {
        $reply = array_shift($this->replies) ?? '';
        if (is_string($reply)) {
            return $reply;
        }

        [$code, $text] = $reply;
        if ($code !== $expect) {
            throw new RuntimeException($text, $code);
        }

        return $text;
    }
}
