<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Exception;

/**
 * The server answered a command with NO or BAD, rather than failing to answer it.
 *
 * @api
 */
final class CommandRefusedException extends RuntimeException {}
