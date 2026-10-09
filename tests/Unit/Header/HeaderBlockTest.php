<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\HeaderBlock;
use Contenir\Mail\Tests\Unit\TestAsset\Growth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(HeaderBlock::class)]
#[Group('unit')]
final class HeaderBlockTest extends TestCase
{
    #[Test]
    public function unfoldsEveryContinuationLineOfAField(): void
    {
        static::assertSame(
            [
                ['Subject: a b c', "Subject: a\r\n b\r\n\tc"],
                ['To: d',          'To: d'],
            ],
            HeaderBlock::fields("Subject: a\r\n b\r\n\tc\r\nTo: d", "\r\n"),
        );
    }

    /**
     * Each continuation line used to copy the whole field so far, so a header folded
     * over 80,000 lines took 17 seconds to read.
     */
    #[Group('slow')]
    #[Test]
    public function unfoldsALongFoldInLinearTime(): void
    {
        $ratio = Growth::ratio(
            static fn(int $lines): string => "Subject: x\r\n" . str_repeat(" ab\r\n", $lines),
            static fn(string $block): array => HeaderBlock::fields($block, "\r\n"),
            size: 2_500,
            factor: 16,
        );

        static::assertLessThan(64, $ratio, 'Unfolding 16 times as many lines took over 64 times as long');
    }
}
