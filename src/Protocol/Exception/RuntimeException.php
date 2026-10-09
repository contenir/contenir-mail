<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Exception;

use Contenir\Mail\Exception;

/**
 * Exception for Contenir\Mail component.
 *
 * @api
 */
class RuntimeException extends Exception\RuntimeException implements ExceptionInterface {}
