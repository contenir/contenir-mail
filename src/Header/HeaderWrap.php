<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Headers;
use Contenir\Mail\Mime\Mime;
use Contenir\Mail\Utf8;

use function preg_match;
use function preg_replace;
use function str_pad;
use function str_starts_with;
use function strlen;
use function substr;
use function wordwrap;

/**
 * Utility class used for creating wrapped or MIME-encoded versions of header
 * values.
 */
// phpcs:ignore WebimpressCodingStandard.NamingConventions.AbstractClass.Prefix
/**
 * @api
 */
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

    /** A line longer than RFC 5322 allows (section 2.1.1) */
    private const string OVERLONG_LINE = '/[^\r\n]{999}/';

    /**
     * Fold a free-text header value to 78 characters, or RFC 2047 encode it as
     * UTF-8 when it is not printable US-ASCII.
     *
     * A printable word too long for one line is left whole, unless it would
     * make a line longer than the 998 characters RFC 5322 allows, such as a
     * long URL without spaces; the value is then written as encoded words,
     * which may be split between any characters.
     *
     * An encoded value starts with a folding line break when not even its
     * first character fits on the first line, as after a long name; see
     * line().
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
        if (1 === preg_match(self::OVERLONG_LINE, $foldedHeaderLine)) {
            return self::mimeEncodeValue($value, firstLineGapSize: $headerNameColonSize);
        }

        return substr($foldedHeaderLine, $headerNameColonSize);
    }

    /**
     * The header line: the name, a colon, and the folded value.
     *
     * A space follows the colon, unless the value starts on the next line
     * after a folding line break (RFC 5322, section 3.2.2, allows folding
     * white space straight after the colon), or the value is empty and
     * "Name: " would not fit in 998 characters.
     */
    public static function line(string $fieldName, string $foldedValue): string
    {
        $noSpace =
            str_starts_with($foldedValue, Headers::FOLDING)
            || ('' === $foldedValue && strlen("{$fieldName}: ") > HeaderLines::MAX_LINE_LENGTH);

        return $fieldName . ($noSpace ? ':' : ': ') . $foldedValue;
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
     * Whether the value can be RFC 2047 encoded: whether it is valid UTF-8.
     *
     * iconv_mime_encode() was used to find out; it fails for invalid UTF-8
     * and for nothing else, which Utf8::isValid() says directly.
     */
    public static function canBeEncoded(string $value): bool
    {
        return Utf8::isValid($value);
    }
}
