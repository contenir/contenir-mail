<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use function preg_match;
use function preg_replace;
use function sprintf;
use function strlen;

/**
 * Header field names: printable US-ASCII except the colon (RFC 5322, section 3.6.8).
 */
final class HeaderName
{
    /**
     * Longest name a built header may have.
     *
     * Every written line must fit in 998 characters (RFC 5322, section
     * 2.1.1), and the first line holds the name, ": " and at least the
     * first word of the value. That word is at worst one encoded byte in an
     * encoded word, "=?UTF-8?Q?=XX?=", 15 characters: 998 - 2 - 15 = 981.
     */
    public const int MAX_LENGTH = 981;

    private function __construct() {}

    /**
     * Drop the characters isValid() rejects.
     */
    public static function filter(string $name): string
    {
        return (string) preg_replace('/[^\x21-\x39\x3B-\x7E]/', replacement: '', subject: $name);
    }

    public static function isValid(string $name): bool
    {
        return 1 === preg_match('/^[\x21-\x39\x3B-\x7E]+$/D', $name);
    }

    /**
     * @throws Exception\InvalidArgumentException When the name is longer than MAX_LENGTH.
     */
    public static function assertLength(string $name): void
    {
        if (strlen($name) > self::MAX_LENGTH) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Header name must be at most %d characters',
                self::MAX_LENGTH,
            ));
        }
    }

    /**
     * @throws Exception\RuntimeException
     */
    public static function assertValid(string $name): void
    {
        if (! self::isValid($name)) {
            throw new Exception\RuntimeException('Invalid header name detected');
        }
    }
}
