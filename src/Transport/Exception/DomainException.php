<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport\Exception;

use Contenir\Mail\Exception;

/**
 * Exception for Contenir\Mail\Transport component.
 */
final class DomainException extends Exception\DomainException implements ExceptionInterface {}
