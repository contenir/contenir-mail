<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\ResponseLimits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseLimits::class)]
#[Group('unit')]
final class ResponseLimitsTest extends TestCase
{
    #[Test]
    public function hasSafeDefaults(): void
    {
        $limits = new ResponseLimits();

        static::assertSame([8_388_608, 67_108_864], [$limits->maxLineLength, $limits->maxResponseSize]);
    }

    #[Test]
    public function acceptsTheSmallestLimits(): void
    {
        $limits = new ResponseLimits(1024, 1024);

        static::assertSame([1024, 1024], [$limits->maxLineLength, $limits->maxResponseSize]);
    }

    #[DataProvider('tooSmallProvider')]
    #[Test]
    public function refusesLimitsBelowTheMinimum(int $line, int $response): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Response limits must be at least 1024 bytes');

        new ResponseLimits($line, $response);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function tooSmallProvider(): array
    {
        return [
            'line'     => [1023, 4096],
            'response' => [1024, 1023],
            'zero'     => [0, 0],
        ];
    }

    #[Test]
    public function refusesALineLimitAboveTheResponseLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The line length limit cannot exceed the response size limit');

        new ResponseLimits(2049, 2048);
    }
}
