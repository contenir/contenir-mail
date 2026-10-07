<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Headers;
use Contenir\Mail\Utf8;

use function array_map;
use function array_values;
use function explode;
use function implode;
use function min;
use function ord;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function str_replace;
use function str_split;
use function strlen;

/**
 * RFC 2047 "Q" encoded words in UTF-8.
 *
 * Words break after a space where they can, and between characters when a
 * run without spaces is too long, so a character is never split. No word is
 * longer than 75 characters (RFC 2047, section 2).
 *
 * @internal Used by HeaderWrap.
 */
final class EncodedWords
{
    /** Characters written as they are inside a "Q" encoded word: printable ASCII except "=", "?", "_" and "," */
    private const string WORD_LITERAL = '/^[\x21-\x2B\x2D-\x3C\x3E\x40-\x5E\x60-\x7E]$/D';

    private const string WORD_PREFIX = '=?UTF-8?Q?';

    private const string WORD_SUFFIX = '?=';

    /** Characters in a word between "=?UTF-8?Q?" and "?=", so no word is longer than 75 (RFC 2047, section 2) */
    private const int MAX_CONTENT = 63;

    /** Characters on the first line between "Name: =?UTF-8?Q?" and "?=", so it is at most 78 long */
    private const int FIRST_LINE_CONTENT = 66;

    /**
     * The value as encoded words, one per folded line, without a trailing line break.
     *
     * @param int $firstLineGap Length of "Name: " before the first word.
     */
    public static function encode(string $value, int $firstLineGap): string
    {
        return self::encodeWords(
            self::encodeCharacters($value, []),
            min(self::MAX_CONTENT, self::FIRST_LINE_CONTENT - $firstLineGap),
        );
    }

    /**
     * As encode(), escaping the given characters too, for a phrase that does not start a line.
     *
     * @param array<string, string> $specials Characters to escape besides the ones every encoded word escapes.
     */
    public static function encodePhrase(string $value, array $specials): string
    {
        return self::encodeWords(self::encodeCharacters($value, $specials), self::MAX_CONTENT);
    }

    /**
     * Each character of the value as it is written inside a "Q" encoded word.
     *
     * @param array<string, string> $specials Characters to escape besides the ones every encoded word escapes.
     * @return list<string>
     */
    private static function encodeCharacters(string $value, array $specials): array
    {
        $encoded = [];
        foreach (Utf8::split($value) as $character) {
            $literal   = 1 === preg_match(self::WORD_LITERAL, $character) ? $character : null;
            $encoded[] = $specials[$character] ?? $literal ?? self::encodeBytes($character);
        }

        return $encoded;
    }

    private static function encodeBytes(string $character): string
    {
        $encoded = '';
        foreach (str_split($character) as $byte) {
            $encoded .= sprintf('=%02X', ord($byte));
        }

        return $encoded;
    }

    /**
     * Fill encoded words with the encoded characters, starting a new word
     * after a space when the next run of characters does not fit, and
     * splitting a run between characters when it fits no word.
     *
     * @param list<string> $characters
     */
    private static function encodeWords(array $characters, int $room): string
    {
        $words   = [];
        $current = '';
        foreach (self::runs($characters) as $run) {
            if ('' !== $current && (strlen($current) + strlen($run)) > $room) {
                $words[] = $current;
                $current = '';
                $room    = self::MAX_CONTENT;
            }

            foreach (self::characters($run) as $encoded) {
                if ('' !== $current && (strlen($current) + strlen($encoded)) > $room) {
                    $words[] = $current;
                    $current = '';
                    $room    = self::MAX_CONTENT;
                }

                $current .= $encoded;
            }
        }

        $words[] = $current;

        return implode(Headers::FOLDING, array_map(
            static fn(string $word): string => self::WORD_PREFIX . $word . self::WORD_SUFFIX,
            $words,
        ));
    }

    /**
     * The encoded characters joined into runs that each end after a space.
     *
     * @param list<string> $characters
     * @return list<string>
     */
    private static function runs(array $characters): array
    {
        return explode("\n", str_replace(
            search: '=20',
            replace: "=20\n",
            subject: implode('', $characters),
        ));
    }

    /**
     * The encoded characters of a run: "=XX" escapes, or single characters.
     *
     * @return list<string>
     */
    private static function characters(string $run): array
    {
        $matches = [];
        preg_match_all('/=[0-9A-F]{2}|./s', $run, $matches);

        return array_values($matches[0] ?? []);
    }
}
