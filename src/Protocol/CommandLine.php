<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use SensitiveParameter;

use function preg_match;

/**
 * Terminates one command line for the wire, refusing any line that could
 * smuggle a second command.
 *
 * Every IMAP and POP3 command goes through here, so a CR, LF or NUL in a
 * user name, password, folder name or search term can never end the command
 * early and start another one.
 *
 * @internal
 */
final class CommandLine
{
    /**
     * @throws Exception\InvalidArgumentException When the line contains CR, LF or NUL; the message never repeats the line, which may hold a password.
     */
    public static function terminate(#[SensitiveParameter] string $line): string
    {
        if (1 === preg_match('/[\r\n\0]/', $line)) {
            throw new Exception\InvalidArgumentException(
                'Refusing to send a command containing CR, LF or NUL; it could inject another command',
            );
        }

        return "{$line}\r\n";
    }
}
