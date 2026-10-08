<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

use Closure;

use function hrtime;
use function max;
use function min;

use const PHP_INT_MAX;

/**
 * Measures how the time a piece of work takes grows with its input, so a test can tell
 * linear from quadratic work without depending on how fast the machine is.
 *
 * The input is built outside the timing, and each size is timed best of five, which
 * keeps a stray pause from deciding the result.
 */
final class Growth
{
    /**
     * How many times longer $run takes on an input $factor times larger: about $factor
     * when the work is linear, and about its square when the work is quadratic.
     *
     * @template T
     * @param Closure(int): T $make Builds the input of the given size.
     * @param Closure(T): mixed $run
     */
    public static function ratio(Closure $make, Closure $run, int $size, int $factor = 4): float
    {
        $small = self::fastest($run, $make($size));
        $large = self::fastest($run, $make($size * $factor));

        return $large / max($small, 1);
    }

    /**
     * @template T
     * @param Closure(T): mixed $run
     * @param T $input
     */
    private static function fastest(Closure $run, mixed $input): int
    {
        $best = PHP_INT_MAX;
        for ($i = 0; $i < 5; $i++) {
            $start = hrtime(true);
            $run($input);
            $best = min($best, hrtime(true) - $start);
        }

        return $best;
    }
}
