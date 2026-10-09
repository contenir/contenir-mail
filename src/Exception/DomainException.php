<?php

declare(strict_types=1);

namespace Contenir\Mail\Exception;

use DomainException as SplDomainException;

/**
 * Exception for Contenir\Mail component.
 *
 * @api
 */
class DomainException extends SplDomainException implements ExceptionInterface {}
