<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

/**
 * An integer-backed enum for reading settings into.
 */
enum Priority: int
{
    case Low  = 1;
    case High = 5;
}
