<?php

declare(strict_types=1);

namespace Contenir\Mail;

use function count;
use function min;
use function ord;
use function preg_match;
use function preg_match_all;
use function preg_replace_callback;
use function str_repeat;
use function strlen;
use function strspn;
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

    /**
     * A run of well-formed characters, or else a run of ill-formed units:
     * maximal subparts of ill-formed sequences, or single bytes that cannot
     * start one, stopping where a well-formed character begins. Scrubbing
     * calls back once per run rather than once per character.
     */
    private const string SCRUB_RUN = '/
        (?:
            [\x00-\x7F]
            | [\xC2-\xDF][\x80-\xBF]
            | \xE0[\xA0-\xBF][\x80-\xBF]
            | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}
            | \xED[\x80-\x9F][\x80-\xBF]
            | \xF0[\x90-\xBF][\x80-\xBF]{2}
            | [\xF1-\xF3][\x80-\xBF]{3}
            | \xF4[\x80-\x8F][\x80-\xBF]{2}
        )++
        | (?:
            (?!
            [\x00-\x7F]
            | [\xC2-\xDF][\x80-\xBF]
            | \xE0[\xA0-\xBF][\x80-\xBF]
            | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}
            | \xED[\x80-\x9F][\x80-\xBF]
            | \xF0[\x90-\xBF][\x80-\xBF]{2}
            | [\xF1-\xF3][\x80-\xBF]{3}
            | \xF4[\x80-\x8F][\x80-\xBF]{2}
            )
            (?:
            \xE0[\xA0-\xBF]
            | [\xE1-\xEC\xEE\xEF][\x80-\xBF]
            | \xED[\x80-\x9F]
            | \xF0[\x90-\xBF][\x80-\xBF]?
            | [\xF1-\xF3][\x80-\xBF]{1,2}
            | \xF4[\x80-\x8F][\x80-\xBF]?
            | [\x80-\xFF]
            )
        )++
    /x';

    /** The UTF-8 continuation bytes, 0x80 to 0xBF */
    private const string CONTINUATION_BYTES = "\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8A\x8B\x8C\x8D\x8E\x8F\x90\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9A\x9B\x9C\x9D\x9E\x9F\xA0\xA1\xA2\xA3\xA4\xA5\xA6\xA7\xA8\xA9\xAA\xAB\xAC\xAD\xAE\xAF\xB0\xB1\xB2\xB3\xB4\xB5\xB6\xB7\xB8\xB9\xBA\xBB\xBC\xBD\xBE\xBF";

    /**
     * Bytes scrubbed per call, so that no run reaches PCRE's backtracking
     * limit (one million steps by default) however long the input is.
     */
    private const int SCRUB_SLICE = 65_536;

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
     * The string in pieces of at most $maxBytes bytes each, split only
     * between characters, as split() finds them. A character longer than
     * $maxBytes is a piece of its own.
     *
     * @return non-empty-list<string>
     */
    public static function chunk(string $value, int $maxBytes): array
    {
        $pieces  = [];
        $current = '';
        foreach (self::split($value) as $character) {
            if ('' !== $current && (strlen($current) + strlen($character)) > $maxBytes) {
                $pieces[] = $current;
                $current  = '';
            }

            $current .= $character;
        }

        $pieces[] = $current;

        return $pieces;
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
        if (self::isValid($value)) {
            return $value;
        }

        $scrubbed = '';
        $length   = strlen($value);
        for ($start = 0; $start < $length; $start = $end) {
            $end   = self::sliceEnd($value, $start + self::SCRUB_SLICE, $length);
            $slice = substr($value, $start, $end - $start);

            $scrubbed .= preg_replace_callback(self::SCRUB_RUN, self::scrubRun(...), $slice) ?? self::scrubByCharacter(
                $slice,
            );
        }

        return $scrubbed;
    }

    /**
     * A well-formed run as it is, or one U+FFFD for each ill-formed unit in an ill-formed run.
     *
     * @param array<array-key, string> $run The match: the run is entry 0.
     */
    private static function scrubRun(array $run): string
    {
        $text = $run[0] ?? '';

        return self::isValid($text) ? $text : str_repeat(self::REPLACEMENT_CHARACTER, times: count(self::split($text)));
    }

    /**
     * Where a slice may end at or after $from: past at most three
     * continuation bytes, which reaches either a byte that starts a new
     * character or ill-formed unit, or a fourth continuation byte, which no
     * sequence can take and so starts a unit of its own.
     */
    private static function sliceEnd(string $value, int $from, int $length): int
    {
        $from = min($from, $length);

        return $from + strspn($value, self::CONTINUATION_BYTES, $from, length: 3);
    }

    /**
     * Scrub one character at a time: slower, but each match is one
     * character, for a slice whose runs still reach PCRE's limits.
     */
    private static function scrubByCharacter(string $value): string
    {
        $scrubbed = '';
        foreach (self::split($value) as $character) {
            $scrubbed .= self::isValid($character) ? $character : self::REPLACEMENT_CHARACTER;
        }

        return $scrubbed;
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
