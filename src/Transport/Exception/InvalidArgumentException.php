<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport\Exception;

use Contenir\Mail\Exception;

/**
 * Exception for Contenir\Mail component.
 *
 * @api
 */
final class InvalidArgumentException extends Exception\InvalidArgumentException implements ExceptionInterface {}
