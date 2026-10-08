<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use function in_array;
use function strlen;

/**
 * @internal
 */
final class ListParser
{
    public const array CHAR_QUOTES = ['\'', '"'];
    public const array CHAR_DELIMS = [',', ';'];
    public const string CHAR_ESCAPE = '\\';

    /**
     * Split a list on its delimiters, ignoring delimiters inside quotes, inside angle
     * brackets (an obsolete source route such as "<@a,@b:jo@example.com>" holds commas)
     * or after a backslash.
     *
     * @param list<string> $delims
     * @return list<string>
     */
    public static function parse(string $value, array $delims = self::CHAR_DELIMS): array
    {
        $values            = [];
        $length            = strlen($value);
        $currentValue      = '';
        $inEscape          = false;
        $inQuote           = false;
        $currentQuoteDelim = null;
        $inAngle           = false;

        for ($i = 0; $i < $length; $i += 1) {
            $char = $value[$i];

            // If we are in an escape sequence, append the character and continue.
            if ($inEscape) {
                $currentValue .= $char;
                $inEscape     = false;
                continue;
            }

            // If we are not in a quoted string, and have a delimiter, append
            // the current value to the list, and reset the current value.
            if (in_array($char, $delims, strict: true) && ! $inQuote && ! $inAngle) {
                $values[]     = $currentValue;
                $currentValue = '';
                continue;
            }

            // Append the character to the current value
            $currentValue .= $char;

            // Escape sequence discovered.
            if (self::CHAR_ESCAPE === $char) {
                $inEscape = true;
                continue;
            }

            if (! $inQuote && ('<' === $char || '>' === $char)) {
                $inAngle = '<' === $char;
                continue;
            }

            // If the character is not a quote character, we are done
            // processing it.
            if (! in_array($char, self::CHAR_QUOTES, strict: true)) {
                continue;
            }

            // If the character matches a previously matched quote delimiter,
            // we reset our quote status and the currently opened quote
            // delimiter.
            if ($char === $currentQuoteDelim) {
                $inQuote           = false;
                $currentQuoteDelim = null;
                continue;
            }

            // If already in quote and the character does not match the previously
            // matched quote delimiter, we're done here.
            if ($inQuote) {
                continue;
            }

            // Otherwise, we're starting a quoted string.
            $inQuote           = true;
            $currentQuoteDelim = $char;
        }

        // If we reached the end of the string and still have a current value,
        // append it to the list (no delimiter was reached).
        if ('' !== $currentValue) {
            $values[] = $currentValue;
        }

        return $values;
    }
}
