<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

/**
 * An enum with the same values as Protocol\Security, to show a reader accepts only cases of the enum it reads.
 */
enum OtherSecurity: string
{
    case Tls = 'tls';
}
