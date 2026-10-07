<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use function preg_match;
use function preg_replace;

/**
 * Header field names: printable US-ASCII except the colon (RFC 5322, section 3.6.8).
 */
final class HeaderName
{
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
     * @throws Exception\RuntimeException
     */
    public static function assertValid(string $name): void
    {
        if (! self::isValid($name)) {
            throw new Exception\RuntimeException('Invalid header name detected');
        }
    }
}
