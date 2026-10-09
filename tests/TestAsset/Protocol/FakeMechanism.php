<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset\Protocol;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Sasl\ExchangeInterface;
use Contenir\Mail\Protocol\Sasl\MechanismInterface;
use Override;

use function array_shift;

/**
 * A SASL mechanism, X-FAKE, that answers challenges from a script and records them,
 * to drive IMAP, POP3 and SMTP through the mechanism interface alone.
 *
 * It cancels any challenge it has no answer left for, and refuses the server's
 * acceptance when told not to trust it.
 */
final class FakeMechanism implements MechanismInterface, ExchangeInterface
{
    public const string NAME = 'X-FAKE';

    /** Why a challenge without an answer is cancelled */
    public const string CANCELLED = 'X-FAKE has no answer to this challenge';

    /** Why an untrusted acceptance is refused */
    public const string UNTRUSTED = 'X-FAKE does not trust this acceptance';

    /** @var list<string> */
    private array $challenges = [];

    /** @var list<string> */
    private array $responses;

    /**
     * @param string|null $initial The initial response, in base64; null for none.
     * @param list<string> $responses The answers to the challenges, in order.
     */
    public function __construct(
        private readonly ?string $initial = null,
        array $responses = [],
        private readonly bool $trustsAcceptance = true,
    ) {
        $this->responses = $responses;
    }

    #[Override]
    public function mechanism(): string
    {
        return self::NAME;
    }

    #[Override]
    public function start(): ExchangeInterface
    {
        return $this;
    }

    #[Override]
    public function initialResponse(): ?string
    {
        return $this->initial;
    }

    #[Override]
    public function respond(string $challenge): string
    {
        $this->challenges[] = $challenge;

        return array_shift($this->responses) ?? throw new RuntimeException(self::CANCELLED);
    }

    #[Override]
    public function complete(): void
    {
        if (! $this->trustsAcceptance) {
            throw new RuntimeException(self::UNTRUSTED);
        }
    }

    #[Override]
    public function refusal(string $reason): string
    {
        return "X-FAKE refused: {$reason}";
    }

    /**
     * The challenges the server sent, as respond() was given them.
     *
     * @return list<string>
     */
    public function challenges(): array
    {
        return $this->challenges;
    }
}
