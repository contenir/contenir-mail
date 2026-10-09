<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset;

use function array_filter;
use function array_values;
use function base64_decode;
use function preg_match;
use function preg_match_all;
use function quoted_printable_decode;
use function str_replace;
use function strtoupper;

/**
 * Reads RFC 2047 encoded words one at a time, without joining adjacent
 * words as a decoder does, so a test can check what each word holds.
 */
final class EncodedWordReader
{
    private const string WORD = '/=\?[^?]+\?[BbQq]\?[^?]*\?=/';

    private function __construct() {}

    /**
     * The encoded words in the text, in order.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $matches = [];
        preg_match_all(self::WORD, $text, $matches);

        return array_values($matches[0] ?? []);
    }

    /**
     * The bytes one encoded word holds, decoded on its own.
     */
    public static function decode(string $word): string
    {
        $parts = [];
        preg_match('/^=\?[^?]+\?([BbQq])\?([^?]*)\?=$/D', $word, $parts);
        $text = $parts[2] ?? '';

        return 'B' === strtoupper($parts[1] ?? '')
            ? (string) base64_decode($text, strict: true)
            : quoted_printable_decode(str_replace(
                search: '_',
                replace: ' ',
                subject: $text,
            ));
    }

    /**
     * The encoded words in the text that do not hold well-formed UTF-8 on their own.
     *
     * @return list<string>
     */
    public static function wordsWithPartialCharacters(string $text): array
    {
        return array_values(array_filter(
            self::words($text),
            static fn(string $word): bool => 1 !== preg_match('//u', self::decode($word)),
        ));
    }
}
