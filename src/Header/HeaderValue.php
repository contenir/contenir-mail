<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use function ord;
use function preg_match;
use function strlen;

final class HeaderValue
{
    /**
     * No public constructor.
     */
    private function __construct() {}

    /**
     * Drop the characters isValid() rejects: bytes outside US-ASCII, DEL, and
     * CR and LF other than CRLF followed by a space (RFC 5322, section 2.2).
     */
    public static function filter(string $value): string
    {
        $result = '';
        $total  = strlen($value);

        // Filter for CR and LF characters, leaving CRLF + WSP sequences for
        // Long Header Fields (section 2.2.3 of RFC 2822)
        for ($i = 0; $i < $total; $i += 1) {
            $ord = ord($value[$i]);
            if (10 === $ord || $ord >= 127) {
                continue;
            }

            if (13 === $ord) {
                if (($i + 2) >= $total) {
                    continue;
                }

                $lf = ord($value[$i + 1]);
                $sp = ord($value[$i + 2]);

                if (10 !== $lf || 32 !== $sp) {
                    continue;
                }

                $result .= "\r\n ";
                $i      += 2;
                continue;
            }

            $result .= $value[$i];
        }

        return $result;
    }

    /**
     * Whether the header value holds only US-ASCII characters other than DEL,
     * with CR and LF only as CRLF followed by a space or tab (RFC 5322, section 2.2).
     */
    public static function isValid(string $value): bool
    {
        return 1 === preg_match('/^(?:[^\r\n\x7F-\xFF]++|\r\n[ \t])*+$/D', $value);
    }

    /**
     * Assert that the header value is valid.
     *
     * Raises an exception if invalid.
     *
     * @param string $value
     * @throws Exception\RuntimeException
     * @return void
     */
    public static function assertValid(string $value): void
    {
        if (! self::isValid($value)) {
            throw new Exception\RuntimeException('Invalid header value detected');
        }
    }
}
