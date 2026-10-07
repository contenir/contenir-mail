<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use function in_array;
use function ord;
use function strlen;

final class HeaderValue
{
    /**
     * No public constructor.
     */
    private function __construct() {}

    /**
     * Filter the header value according to RFC 2822
     *
     * @see    http://www.rfc-base.org/txt/rfc-2822.txt (section 2.2)
     *
     * @param  string $value
     * @return string
     */
    public static function filter(string $value): string
    {
        $result = '';
        $total  = strlen($value);

        // Filter for CR and LF characters, leaving CRLF + WSP sequences for
        // Long Header Fields (section 2.2.3 of RFC 2822)
        for ($i = 0; $i < $total; $i += 1) {
            $ord = ord($value[$i]);
            if (10 === $ord || $ord > 127) {
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
     * Determine if the header value contains any invalid characters.
     *
     * @see    http://www.rfc-base.org/txt/rfc-2822.txt (section 2.2)
     *
     * @param string $value
     * @return bool
     */
    public static function isValid(string $value): bool
    {
        $total = strlen($value);
        for ($i = 0; $i < $total; $i += 1) {
            $ord = ord($value[$i]);

            // bare LF means we aren't valid
            if (10 === $ord || $ord > 127) {
                return false;
            }

            if (13 === $ord) {
                if (($i + 2) >= $total) {
                    return false;
                }

                $lf = ord($value[$i + 1]);
                $sp = ord($value[$i + 2]);

                if (10 !== $lf || ! in_array($sp, [9, 32], strict: true)) {
                    return false;
                }

                // skip over the LF following this
                $i += 2;
            }
        }

        return true;
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
