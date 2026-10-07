<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use function base64_decode;
use function iconv;
use function preg_match;
use function preg_split;
use function quoted_printable_decode;
use function restore_error_handler;
use function set_error_handler;
use function str_replace;
use function strtoupper;
use function trim;

use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Decodes RFC 2047 encoded words, joining adjacent words of the same charset
 * before conversion so a multibyte character split across them survives.
 *
 * @internal Used by HeaderWrap when iconv cannot decode a value.
 */
final class EncodedWordDecoder
{
    private const string ENCODED_WORD = '/(=\?[^?*]+(?:\*[^?]*)?\?[BbQq]\?[^?]*\?=)/';

    private const string WORD_PARTS = '/^=\?(?<charset>[^?*]+)(?:\*[^?]*)?\?(?<scheme>[BbQq])\?(?<text>[^?]*)\?=$/';

    private function __construct() {}

    public static function decode(string $value): string
    {
        // Text and encoded words alternate: text, word, text, word, ..., text
        $tokens = preg_split(self::ENCODED_WORD, $value, flags: PREG_SPLIT_DELIM_CAPTURE);
        // @codeCoverageIgnoreStart
        // Unreachable: preg_split() only fails on an invalid pattern, and this one is constant
        if (false === $tokens) {
            return $value;
        }

        // @codeCoverageIgnoreEnd

        $result  = '';
        $buffer  = '';
        $charset = null;
        $text    = '';
        foreach ($tokens as $index => $token) {
            if (0 === ($index % 2)) {
                $text = $token;
                continue;
            }

            [$wordCharset, $bytes] = self::decodeWord($token);

            // Whitespace between adjacent encoded words is not displayed (RFC 2047, section 6.2)
            $joinsPrevious = null !== $charset && '' === trim($text);
            if (! $joinsPrevious || $wordCharset !== $charset) {
                $result .= self::toUtf8($buffer, $charset) . ($joinsPrevious ? '' : $text);
                $buffer = '';
            }

            $charset = $wordCharset;
            $buffer  .= $bytes;
        }

        return $result . self::toUtf8($buffer, $charset) . $text;
    }

    /**
     * @return array{string, string} the upper-cased charset and the decoded bytes
     */
    private static function decodeWord(string $word): array
    {
        $matches = [];
        preg_match(self::WORD_PARTS, $word, $matches);
        $text = $matches['text'] ?? '';

        /** @mago-expect lint:strict-behavior Mail in the wild carries stray characters in encoded words; mail clients decode it leniently, and so do we. */
        $bytes = 'B' === strtoupper($matches['scheme'] ?? '')
            ? (string) base64_decode($text, strict: false)
            : quoted_printable_decode(str_replace(
                search: '_',
                replace: ' ',
                subject: $text,
            ));

        return [strtoupper($matches['charset'] ?? 'UTF-8'), $bytes];
    }

    private static function toUtf8(string $value, ?string $charset): string
    {
        if ('' === $value || null === $charset || 'UTF-8' === $charset) {
            return $value;
        }

        // iconv warns about charsets it does not know; leave such text as it is
        set_error_handler(static fn(): bool => true);
        $converted = iconv($charset, to_encoding: 'UTF-8', string: $value);
        restore_error_handler();

        return false === $converted ? $value : $converted;
    }
}
