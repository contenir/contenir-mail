<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Utf8;

use function count;
use function implode;
use function ord;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_split;
use function strlen;
use function strtolower;
use function trim;

/**
 * Validates and writes the parameters of structured MIME headers,
 * `value; name="value"; ...` (RFC 2045, section 5.1).
 *
 * ASCII values are quoted, with quotes and backslashes escaped as quoted
 * pairs; other values are written as RFC 2231 UTF-8 extended values. A
 * parameter too long for one line is split into numbered continuation
 * sections. MimeParameterParser reads them back.
 *
 * @internal Used by ContentType and ContentDisposition.
 */
final class MimeParameters
{
    /** Longest parameter, or continuation section, written on one line */
    public const int MAX_SEGMENT_LENGTH = 76;

    /**
     * RFC 2045 token: printable US-ASCII except space and tspecials, at most 127 characters as
     * RFC 6838 limits media type names, so a header line holding one stays within 998.
     */
    private const string TOKEN_CHARACTERS = '/^[!#$%&\'*+\-.0-9A-Z^_`a-z{|}~]{1,127}$/D';

    /** RFC 2231 attribute-char: token characters except "*", "'" and "%" */
    private const string ATTRIBUTE_CHARACTER = '/^[!#$&+\-.0-9A-Z^_`a-z{|}~]$/D';

    /** Controls other than tab, which no parameter value may hold */
    private const string CONTROL_CHARACTERS = '/[\x00-\x08\x0A-\x1F\x7F]/';

    public static function isToken(string $value): bool
    {
        return 1 === preg_match(self::TOKEN_CHARACTERS, $value);
    }

    /**
     * The parameter name, lower-cased.
     *
     * @throws Exception\InvalidArgumentException When the name is not an RFC 2045 token, or holds "*".
     */
    public static function name(string $name, string $context): string
    {
        $name = strtolower(trim($name));
        if (! self::isToken($name) || str_contains($name, '*')) {
            throw new Exception\InvalidArgumentException("Invalid {$context} parameter name detected");
        }

        return $name;
    }

    /**
     * @throws Exception\InvalidArgumentException When the value is not UTF-8 or holds a control character.
     */
    public static function value(string $value): string
    {
        if (! Utf8::isValid($value) || 1 === preg_match(self::CONTROL_CHARACTERS, $value)) {
            throw new Exception\InvalidArgumentException(
                'Parameter value must be composed of printable US-ASCII or UTF-8 characters.',
            );
        }

        return $value;
    }

    /**
     * The parameter as written: `name="value"` for ASCII, `name*=UTF-8''…`
     * otherwise, split into numbered continuation sections when it is too
     * long for one line.
     *
     * @return list<string>
     */
    public static function segments(string $name, string $value): array
    {
        if (1 === preg_match('/^[\x20-\x7E]*\z/', $value)) {
            return self::fit(
                Utf8::split($value),
                static fn(string $text): string => sprintf('%s="%s"', $name, self::quote($text)),
                static fn(int $index, string $text): string => sprintf('%s*%d="%s"', $name, $index, self::quote($text)),
            );
        }

        $characters = [];
        foreach (Utf8::split($value) as $character) {
            $characters[] = self::percentEncode($character);
        }

        return self::fit(
            $characters,
            static fn(string $text): string => sprintf("%s*=UTF-8''%s", $name, $text),
            static fn(int $index, string $text): string => 0 === $index
                ? sprintf("%s*0*=UTF-8''%s", $name, $text)
                : sprintf('%s*%d*=%s', $name, $index, $text),
        );
    }

    /**
     * One segment when the whole value fits a line, otherwise sections
     * filled with as many characters as fit, and at least one, so a long
     * name can never stall the split.
     *
     * @param list<string> $characters
     * @param callable(string): string $whole
     * @param callable(int, string): string $section
     * @return list<string>
     */
    private static function fit(array $characters, callable $whole, callable $section): array
    {
        $single = $whole(implode('', $characters));
        if (strlen($single) < self::MAX_SEGMENT_LENGTH) {
            return [$single];
        }

        $sections = [];
        $current  = '';
        foreach ($characters as $character) {
            $index     = count($sections);
            $candidate = $current . $character;
            if ('' !== $current && strlen($section($index, $candidate)) > self::MAX_SEGMENT_LENGTH) {
                $sections[] = $section($index, $current);
                $candidate  = $character;
            }

            $current = $candidate;
        }

        $sections[] = $section(count($sections), $current);

        return $sections;
    }

    private static function quote(string $value): string
    {
        return str_replace(
            search: ['\\', '"'],
            replace: ['\\\\', '\\"'],
            subject: $value,
        );
    }

    private static function percentEncode(string $character): string
    {
        if (1 === preg_match(self::ATTRIBUTE_CHARACTER, $character)) {
            return $character;
        }

        $encoded = '';
        foreach (str_split($character) as $byte) {
            $encoded .= sprintf('%%%02X', ord($byte));
        }

        return $encoded;
    }
}
