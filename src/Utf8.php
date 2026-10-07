<?php

declare(strict_types=1);

namespace Contenir\Mail;

use function array_map;
use function implode;
use function ord;
use function preg_match;
use function preg_match_all;
use function strlen;
use function substr;

/**
 * UTF-8 text handling with PCRE, so that ext-mbstring is not needed.
 *
 * Invalid input is read as the Unicode Standard recommends (chapter 3,
 * "U+FFFD Substitution of Maximal Subparts"), as mbstring does: each
 * maximal subpart of an ill-formed sequence, or else each single byte that
 * cannot start one, counts as one character.
 *
 * @internal
 */
final class Utf8
{
    /**
     * One well-formed character, or else one maximal subpart of an
     * ill-formed sequence, or else any single byte.
     */
    private const string CHARACTER = '/
        [\x00-\x7F]
        | [\xC2-\xDF][\x80-\xBF]
        | \xE0[\xA0-\xBF][\x80-\xBF]
        | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}
        | \xED[\x80-\x9F][\x80-\xBF]
        | \xF0[\x90-\xBF][\x80-\xBF]{2}
        | [\xF1-\xF3][\x80-\xBF]{3}
        | \xF4[\x80-\x8F][\x80-\xBF]{2}
        | \xE0[\xA0-\xBF]
        | [\xE1-\xEC\xEE\xEF][\x80-\xBF]
        | \xED[\x80-\x9F]
        | \xF0[\x90-\xBF][\x80-\xBF]?
        | [\xF1-\xF3][\x80-\xBF]{1,2}
        | \xF4[\x80-\x8F][\x80-\xBF]?
        | [\x80-\xFF]
    /x';

    private const string REPLACEMENT_CHARACTER = "\u{FFFD}";

    private function __construct() {}

    /**
     * Whether the string is well-formed UTF-8: no overlong forms,
     * surrogates, code points above U+10FFFF or truncated sequences.
     */
    public static function isValid(string $value): bool
    {
        return 1 === preg_match('//u', $value);
    }

    /**
     * The characters of the string, one entry per code point.
     *
     * Each maximal subpart of an ill-formed sequence is an entry of its own,
     * so the entries always join back into the string. mbstring's split
     * function splits ill-formed input by lead byte alone instead.
     *
     * @return list<string>
     */
    public static function split(string $value): array
    {
        $matches = [];
        preg_match_all(self::CHARACTER, $value, $matches);

        /** @mago-expect analysis:invalid-return-statement Pattern-ordered matches are a list. */
        return $matches[0] ?? [];
    }

    /**
     * The number of code points, each maximal subpart of an ill-formed
     * sequence counting as one, as mbstring counts them.
     */
    public static function length(string $value): int
    {
        return (int) preg_match_all(self::CHARACTER, $value);
    }

    /**
     * The string with each maximal subpart of an ill-formed sequence
     * replaced by U+FFFD REPLACEMENT CHARACTER, as mbstring's scrub function
     * replaces them when its substitute character is U+FFFD.
     */
    public static function scrub(string $value): string
    {
        return implode('', array_map(
            static fn(string $character): string => self::isValid($character)
                ? $character
                : self::REPLACEMENT_CHARACTER,
            self::split($value),
        ));
    }

    /**
     * The longest prefix of at most $maxBytes bytes that does not end inside
     * a character, as mbstring's cut function gives from offset 0 for
     * well-formed input.
     *
     * The cut moves back over continuation bytes until it reaches a byte
     * that starts a character. mbstring cuts ill-formed input in ways
     * that differ between PHP versions; this does not try to match them.
     *
     * @param int<0, max> $maxBytes
     */
    public static function cut(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $end = $maxBytes;
        while ($end > 0 && 0x80 === (ord($value[$end]) & 0xC0)) {
            $end--;
        }

        return substr($value, offset: 0, length: $end);
    }
}
