<?php

declare(strict_types=1);

namespace Contenir\Mail\Exception;

use OutOfBoundsException as SplOutOfBoundsException;

/**
 * Exception for Contenir\Mail component.
 *
 * @api
 */
class OutOfBoundsException extends SplOutOfBoundsException implements ExceptionInterface {}
