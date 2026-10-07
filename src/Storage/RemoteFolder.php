<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use function preg_match;

/**
 * Checks folder names sent to an IMAP server.
 *
 * The protocol quotes or sends names as literals, but a name holding a line
 * break or NUL has no meaning to a server and would end the command early
 * on a careless one, so it is refused before it is sent.
 *
 * @internal
 */
final class RemoteFolder
{
    /**
     * @throws Exception\InvalidArgumentException When the name is empty or holds CR, LF or NUL.
     */
    public static function check(string $name): string
    {
        if ('' === $name || 1 === preg_match('/[\r\n\0]/', $name)) {
            throw new Exception\InvalidArgumentException('A folder name may not be empty or hold a line break or NUL');
        }

        return $name;
    }

    /**
     * As check(), allowing the empty name, which means the root.
     *
     * @throws Exception\InvalidArgumentException When the name holds CR, LF or NUL.
     */
    public static function checkOptional(string $name): string
    {
        return '' === $name ? $name : self::check($name);
    }
}
