<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use function ord;
use function strlen;

/**
 * Header field names: printable US-ASCII except the colon (RFC 5322, section 3.6.8).
 */
final class HeaderName
{
    private function __construct() {}

    public static function filter(string $name): string
    {
        $result = '';
        $total  = strlen($name);
        for ($i = 0; $i < $total; ++$i) {
            $ord = ord($name[$i]);
            if ($ord > 32 && $ord < 127 && 58 !== $ord) {
                $result .= $name[$i];
            }
        }

        return $result;
    }

    public static function isValid(string $name): bool
    {
        $total = strlen($name);
        if (0 === $total) {
            return false;
        }

        for ($i = 0; $i < $total; ++$i) {
            $ord = ord($name[$i]);
            if ($ord < 33 || $ord > 126 || 58 === $ord) {
                return false;
            }
        }

        return true;
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
