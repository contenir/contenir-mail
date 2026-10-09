<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset;

/**
 * An integer-backed enum for reading settings into.
 */
enum Priority: int
{
    case None = 0;
    case Low  = 1;
    case High = 5;
}
