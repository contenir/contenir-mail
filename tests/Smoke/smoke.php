<?php

/**
 * Sends a message through a real mail provider with your own account, reads it
 * back, and checks that a bad access token is refused cleanly. Nothing is
 * stored, and secrets are never printed. See tests/Smoke/README.md.
 *
 * php tests/Smoke/smoke.php
 */

declare(strict_types=1);

namespace Contenir\Mail\Tests\Smoke;

use RuntimeException;

use function fwrite;

use const STDERR;

require __DIR__ . '/../../vendor/autoload.php';

try {
    $account = Account::fromEnvironment();
} catch (RuntimeException $e) {
    fwrite(STDERR, "{$e->getMessage()}\n");

    exit(2);
}

exit((new SmokeChecks($account, new MicrosoftDeviceCode()))->run());
