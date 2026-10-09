<?php

declare(strict_types=1);

namespace Contenir\Mail\Exception;

use BadMethodCallException as SplBadMethodCallException;

/**
 * Exception for Contenir\Mail component.
 *
 * @deprecated 0.3.0 Nothing in the library throws it; catch ExceptionInterface instead.
 * @api
 */
final class BadMethodCallException extends SplBadMethodCallException implements ExceptionInterface {}
