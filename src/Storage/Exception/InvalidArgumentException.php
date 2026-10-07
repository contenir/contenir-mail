<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Exception;

use Contenir\Mail\Exception;

/**
 * Thrown by the mail storages.
 *
 * @api
 */
final class InvalidArgumentException extends Exception\InvalidArgumentException implements ExceptionInterface {}
