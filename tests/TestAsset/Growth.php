<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset;

use Closure;

use function hrtime;
use function max;
use function min;

use const PHP_INT_MAX;

/**
 * Measures how the time a piece of work takes grows with its input, so a test can tell
 * linear from quadratic work without depending on how fast the machine is.
 *
 * The inputs are built outside the timing, and the two sizes are timed in turn, best of
 * seven, so a stray pause or a busy machine slows both rather than deciding the result.
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
        $small     = $make($size);
        $large     = $make($size * $factor);
        $bestSmall = PHP_INT_MAX;
        $bestLarge = PHP_INT_MAX;
        for ($i = 0; $i < 7; $i++) {
            $bestSmall = min($bestSmall, self::time($run, $small));
            $bestLarge = min($bestLarge, self::time($run, $large));
        }

        return $bestLarge / max($bestSmall, 1);
    }

    /**
     * @template T
     * @param Closure(T): mixed $run
     * @param T $input
     */
    private static function time(Closure $run, mixed $input): int
    {
        $start = hrtime(true);
        $run($input);

        return hrtime(true) - $start;
    }
}
