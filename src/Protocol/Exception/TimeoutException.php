<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Exception;

/**
 * The server did not answer within the connection's timeout.
 *
 * @api
 */
final class TimeoutException extends RuntimeException {}
