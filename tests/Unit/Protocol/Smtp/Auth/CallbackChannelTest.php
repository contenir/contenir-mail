<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp\Auth;

use Contenir\Mail\Protocol\Smtp\Auth\CallbackChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallbackChannel::class)]
#[Group('unit')]
final class CallbackChannelTest extends TestCase
{
    #[Test]
    public function passesPlainStepToFirstCallback(): void
    {
        $channel = new CallbackChannel(
            static fn(string $line, int $expect): string => "plain {$line} {$expect}",
            static fn(string $line, int $expect): string => "secret {$line} {$expect}",
        );

        static::assertSame('plain AUTH LOGIN 334', $channel->exchange('AUTH LOGIN', 334));
    }

    #[Test]
    public function passesSecretStepToSecondCallback(): void
    {
        $channel = new CallbackChannel(
            static fn(string $line, int $expect): string => "plain {$line} {$expect}",
            static fn(string $line, int $expect): string => "secret {$line} {$expect}",
        );

        static::assertSame('secret c2VjcmV0 235', $channel->exchangeSecret('c2VjcmV0', 235));
    }
}
