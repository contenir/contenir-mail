<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Headers;
use Contenir\Mail\Mime\Mime;

use function iconv_mime_encode;
use function mb_check_encoding;
use function preg_replace;
use function str_pad;
use function strlen;
use function substr;
use function wordwrap;

/**
 * Utility class used for creating wrapped or MIME-encoded versions of header
 * values.
 */
// phpcs:ignore WebimpressCodingStandard.NamingConventions.AbstractClass.Prefix
final class HeaderWrap
{
    /** Characters escaped in addition inside an encoded phrase */
    private const array PHRASE_SPECIALS = [
        '"'  => '=22',
        '('  => '=28',
        ')'  => '=29',
        '.'  => '=2E',
        ':'  => '=3A',
        ';'  => '=3B',
        '<'  => '=3C',
        '>'  => '=3E',
        '@'  => '=40',
        '['  => '=5B',
        '\\' => '=5C',
        ']'  => '=5D',
    ];

    /**
     * Fold a free-text header value to 78 characters, or RFC 2047 encode it as
     * UTF-8 when it is not printable US-ASCII.
     *
     * A printable word too long for one line is left whole; a transport
     * splits lines over its limit.
     */
    public static function fold(string $fieldName, string $value): string
    {
        $headerNameColonSize = strlen("{$fieldName}: ");

        if (! Mime::isPrintable($value)) {
            return self::mimeEncodeValue($value, firstLineGapSize: $headerNameColonSize);
        }

        // Pad the value by the length of "Name: " so the first line folds at the right column.
        $headerLine       = str_pad('0', $headerNameColonSize, pad_string: '0') . $value;
        $foldedHeaderLine = wordwrap($headerLine, width: 78, break: Headers::FOLDING);
        return substr($foldedHeaderLine, $headerNameColonSize);
    }

    /**
     * RFC 2047 encode a UTF-8 value as quoted-printable encoded words, folded
     * between words, without a trailing line break.
     *
     * Words break after a space where they can, and between characters when
     * a run without spaces is too long. No word is longer than 75 characters
     * (RFC 2047, section 2), and no line longer than $lineLength.
     *
     * @param int<0, max> $firstLineGapSize Length of "Name: " before the value, so the first line folds in time.
     */
    public static function mimeEncodeValue(string $value, int $firstLineGapSize): string
    {
        return EncodedWords::encode($value, $firstLineGapSize);
    }

    /**
     * RFC 2047 encode a display name or other phrase.
     *
     * Besides what mimeEncodeValue() escapes, the RFC 5322 specials are
     * escaped too, because an encoded word in a phrase may hold only letters,
     * digits and "!*+-/=_" (RFC 2047, section 5).
     */
    public static function encodePhrase(string $value): string
    {
        return EncodedWords::encodePhrase($value, self::PHRASE_SPECIALS);
    }

    /**
     * Unfold a header value and decode any RFC 2047 encoded words in it to UTF-8.
     */
    public static function mimeDecodeValue(string $value): string
    {
        // Unfold first (RFC 5322, section 2.2.3): a line break followed by a space or tab joins the lines
        return EncodedWordDecoder::decode((string) preg_replace('/\r\n[ \t]/', replacement: ' ', subject: $value));
    }

    /**
     * Test if is possible apply MIME-encoding
     *
     * @param string $value
     * @return bool
     */
    public static function canBeEncoded(string $value): bool
    {
        if (! mb_check_encoding($value, encoding: 'UTF-8')) {
            return false;
        }

        // avoid any wrapping by specifying line length long enough
        // "test" -> 4
        // "x-test: =?ISO-8859-1?B?dGVzdA==?=" -> 33
        //  8       +2          +3         +3  -> 16
        $charset    = 'UTF-8';
        $lineLength = (strlen($value) * 4) + strlen($charset) + 16;

        $preferences = [
            'scheme'         => 'Q',
            'input-charset'  => $charset,
            'output-charset' => $charset,
            'line-length'    => $lineLength,
        ];

        $encoded = iconv_mime_encode('x-test', $value, $preferences);

        return false !== $encoded;
    }
}
