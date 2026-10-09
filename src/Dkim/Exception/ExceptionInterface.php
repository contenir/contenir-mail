<?php

declare(strict_types=1);

namespace Contenir\Mail\Dkim\Exception;

use Contenir\Mail\Exception\ExceptionInterface as MailException;

/**
 * Marks exceptions raised by DKIM signing.
 *
 * @api
 */
interface ExceptionInterface extends MailException {}
