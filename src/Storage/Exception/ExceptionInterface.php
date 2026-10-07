<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Exception;

use Contenir\Mail\Exception\ExceptionInterface as MailException;

/**
 * Thrown by the mail storages.
 *
 * @api
 */
interface ExceptionInterface extends MailException {}
