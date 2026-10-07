<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use function preg_match;
use function preg_replace;

final class HeaderValue
{
    /**
     * No public constructor.
     */
    private function __construct() {}

    /**
     * Drop the characters isValid() rejects: bytes outside US-ASCII, control
     * characters other than tab, and CR and LF other than CRLF followed by a
     * space (RFC 5322, section 2.2).
     */
    public static function filter(string $value): string
    {
        return (string) preg_replace('/(\r\n )|[^\t\x20-\x7E]/', replacement: '$1', subject: $value);
    }

    /**
     * Whether the header value holds only printable US-ASCII, spaces and
     * tabs, with CR and LF only as CRLF followed by a space or tab (RFC 5322,
     * section 2.2). Such a value can be written as it is.
     */
    public static function isValid(string $value): bool
    {
        return 1 === preg_match('/^(?:[\t\x20-\x7E]++|\r\n[ \t])*+$/D', $value);
    }

    /**
     * Whether a header value read from a message is acceptable: isValid(), or
     * raw UTF-8 as RFC 6532 allows. Invalid UTF-8 and control characters other
     * than tab, including the C1 controls U+0080 to U+009F, are refused.
     *
     * Composing still encodes such a value as RFC 2047 encoded words.
     */
    public static function isValidUtf8(string $value): bool
    {
        return 1 === preg_match('/^(?:[^\x00-\x08\x0A-\x1F\x7F-\x{9F}]++|\r\n[ \t])*+$/Du', $value);
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
