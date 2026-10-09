<?php

declare(strict_types=1);

namespace Contenir\Mail\Exception;

use InvalidArgumentException as SplInvalidArgumentException;

/**
 * Exception for Contenir\Mail component.
 *
 * @api
 */
class InvalidArgumentException extends SplInvalidArgumentException implements ExceptionInterface {}
