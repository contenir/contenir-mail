<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Exception;

use Contenir\Mail\Exception;

/**
 * A mistake in how the library is used, such as serializing an object that
 * holds a live connection.
 *
 * @api
 */
final class LogicException extends Exception\LogicException implements ExceptionInterface {}
