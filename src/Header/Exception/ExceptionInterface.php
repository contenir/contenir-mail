<?php

declare(strict_types=1);

namespace Contenir\Mail\Header\Exception;

use Contenir\Mail\Exception\ExceptionInterface as MailException;

/**
 * Marks exceptions raised by the header classes.
 *
 * @api
 */
interface ExceptionInterface extends MailException {}
