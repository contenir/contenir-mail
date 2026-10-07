<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport\Exception;

use Contenir\Mail\Exception\ExceptionInterface as MailException;

/**
 * Thrown by the transports.
 *
 * @api
 */
interface ExceptionInterface extends MailException {}
