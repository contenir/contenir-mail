<?php

declare(strict_types=1);

namespace Contenir\Mail\Exception;

use LogicException as SplLogicException;

/**
 * A mistake in how the library is used, such as serializing an object that
 * holds a live connection.
 *
 * @api
 */
class LogicException extends SplLogicException implements ExceptionInterface {}
