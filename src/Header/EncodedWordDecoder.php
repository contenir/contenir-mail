<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Protocol\ErrorCapture;
use Contenir\Mail\Utf8;

use function base64_decode;
use function iconv;
use function in_array;
use function preg_match;
use function preg_split;
use function quoted_printable_decode;
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

    /**
     * Charsets decoded through iconv: the ones mail is written in. Text in any
     * other charset named by a message is left as it is, so a hostile message
     * cannot choose an obscure converter (glibc's ISO-2022-CN-EXT overflow,
     * CVE-2024-2961, is the reason to be choosy).
     */
    private const array CHARSETS = [
        'US-ASCII',
        'ASCII',
        'UTF-16',
        'UTF-16BE',
        'UTF-16LE',
        'ISO-8859-1',
        'ISO-8859-2',
        'ISO-8859-3',
        'ISO-8859-4',
        'ISO-8859-5',
        'ISO-8859-6',
        'ISO-8859-7',
        'ISO-8859-8',
        'ISO-8859-9',
        'ISO-8859-10',
        'ISO-8859-11',
        'ISO-8859-13',
        'ISO-8859-14',
        'ISO-8859-15',
        'ISO-8859-16',
        'LATIN1',
        'WINDOWS-1250',
        'WINDOWS-1251',
        'WINDOWS-1252',
        'WINDOWS-1253',
        'WINDOWS-1254',
        'WINDOWS-1255',
        'WINDOWS-1256',
        'WINDOWS-1257',
        'WINDOWS-1258',
        'CP1250',
        'CP1251',
        'CP1252',
        'KOI8-R',
        'KOI8-U',
        'TIS-620',
        'ISO-2022-JP',
        'SHIFT_JIS',
        'SHIFT-JIS',
        'CP932',
        'EUC-JP',
        'EUC-KR',
        'ISO-2022-KR',
        'GB2312',
        'GBK',
        'GB18030',
        'BIG5',
        'BIG5-HKSCS',
    ];

    private const string WORD_PARTS = '/^=\?(?<charset>[^?*]+)(?:\*[^?]*)?\?(?<scheme>[BbQq])\?(?<text>[^?]*)\?=$/';

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

    /**
     * Convert text in the named charset to UTF-8, leaving it as it is when the
     * charset is not one mail is written in, or iconv does not know it.
     */
    public static function toUtf8(string $value, ?string $charset): string
    {
        $charset = null === $charset ? null : strtoupper($charset);
        if (! in_array($charset, self::CHARSETS, strict: true)) {
            return $value;
        }

        [$converted] = ErrorCapture::run(static fn(): string|false => iconv(
            $charset,
            to_encoding: 'UTF-8',
            string: $value,
        ));

        return false === $converted ? $value : $converted;
    }

    /**
     * The value as valid UTF-8, any invalid byte sequences replaced.
     */
    public static function scrub(string $value): string
    {
        return Utf8::scrub($value);
    }
}
