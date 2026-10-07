<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\TestAsset;

use Contenir\Mail\Protocol\ProtocolTrait;

/**
 * A class using ProtocolTrait, for subclasses that call setupSocket() as Protocol\Smtp's do.
 */
abstract class AbstractSocketOpener
{
    use ProtocolTrait;
}
