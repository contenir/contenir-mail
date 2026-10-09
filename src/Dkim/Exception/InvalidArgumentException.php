<?php

declare(strict_types=1);

namespace Contenir\Mail\Dkim\Exception;

use Contenir\Mail\Exception;

/**
 * @api
 */
final class InvalidArgumentException extends Exception\InvalidArgumentException implements ExceptionInterface {}
