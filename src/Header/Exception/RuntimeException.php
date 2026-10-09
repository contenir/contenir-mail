<?php

declare(strict_types=1);

namespace Contenir\Mail\Header\Exception;

use Contenir\Mail\Exception;

/**
 * @api
 */
final class RuntimeException extends Exception\RuntimeException implements ExceptionInterface {}
