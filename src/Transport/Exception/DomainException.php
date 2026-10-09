<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport\Exception;

use Contenir\Mail\Exception;

/**
 * Exception for Contenir\Mail\Transport component.
 *
 * @deprecated 0.3.0 Nothing in the library throws it; catch ExceptionInterface instead.
 * @api
 */
final class DomainException extends Exception\DomainException implements ExceptionInterface {}
