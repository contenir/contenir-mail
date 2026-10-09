<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime\Exception;

use Contenir\Mail\Exception;

/**
 * Exception for Contenir\Mail\Mime component.
 *
 * @final Released as extendable in 0.2; it will be final in 1.0.
 * @api
 */
class RuntimeException extends Exception\RuntimeException implements ExceptionInterface {}
