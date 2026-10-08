<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Smoke;

use RuntimeException;

/**
 * A check that cannot run with the credentials given.
 */
final class SkippedException extends RuntimeException {}
