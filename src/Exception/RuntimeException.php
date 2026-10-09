<?php

declare(strict_types=1);

namespace Contenir\Mail\Exception;

use RuntimeException as SplRuntimeException;

/**
 * Exception for Contenir\Mail component.
 *
 * @api
 */
class RuntimeException extends SplRuntimeException implements ExceptionInterface {}
