<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

use Contenir\Mail\Utf8;

use function array_map;
use function base64_encode;
use function chunk_split;
use function count;
use function explode;
use function implode;
use function intdiv;
use function ord;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strcspn;
use function strlen;
use function strrpos;
use function strtoupper;
use function strtr;
use function substr;
use function trim;

/**
 * Support class for MultiPart Mime Messages
 *
 * @mago-expect lint:too-many-methods The RFC 2045 and 2047 encoders kept from laminas-mime, as one static utility.
 */
final class Mime
{
    // phpcs:disable Generic.Files.LineLength.TooLong
    public const string TYPE_OCTETSTREAM         = 'application/octet-stream';
    public const string TYPE_TEXT                = 'text/plain';
    public const string TYPE_HTML                = 'text/html';
    public const string TYPE_ENRICHED            = 'text/enriched';
    public const string TYPE_XML                 = 'text/xml';
    public const string ENCODING_7BIT            = '7bit';
    public const string ENCODING_8BIT            = '8bit';
    public const string ENCODING_QUOTEDPRINTABLE = 'quoted-printable';
    public const string ENCODING_BASE64          = 'base64';
    public const string DISPOSITION_ATTACHMENT   = 'attachment';
    public const string DISPOSITION_INLINE       = 'inline';
    public const int LINELENGTH               = 72;
    public const string LINEEND                  = "\n";
    public const string MULTIPART_ALTERNATIVE    = 'multipart/alternative';
    public const string MULTIPART_MIXED          = 'multipart/mixed';
    public const string MULTIPART_RELATED        = 'multipart/related';
    public const string MULTIPART_RELATIVE       = 'multipart/relative';
    public const string MULTIPART_REPORT         = 'multipart/report';
    public const string MESSAGE_RFC822           = 'message/rfc822';
    public const string MESSAGE_DELIVERY_STATUS  = 'message/delivery-status';
    public const string CHARSET_REGEX            = '#=\?(?P<charset>[\x21\x23-\x26\x2a\x2b\x2d\x5e\x5f\x60\x7b-\x7ea-zA-Z0-9]+)\?(?P<encoding>[\x21\x23-\x26\x2a\x2b\x2d\x5e\x5f\x60\x7b-\x7ea-zA-Z0-9]+)\?(?P<text>[\x21-\x3e\x40-\x7e]+)#';

    // phpcs:enable

    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

    /**
     * Each byte quoted-printable must encode, with its encoding, for strtr() to replace in one pass.
     *
     * @var array<string, string>
     */
    private const array QP_MAP = [
        '='    => '=3D',
        "\x00" => '=00',
        "\x01" => '=01',
        "\x02" => '=02',
        "\x03" => '=03',
        "\x04" => '=04',
        "\x05" => '=05',
        "\x06" => '=06',
        "\x07" => '=07',
        "\x08" => '=08',
        "\x09" => '=09',
        "\x0A" => '=0A',
        "\x0B" => '=0B',
        "\x0C" => '=0C',
        "\x0D" => '=0D',
        "\x0E" => '=0E',
        "\x0F" => '=0F',
        "\x10" => '=10',
        "\x11" => '=11',
        "\x12" => '=12',
        "\x13" => '=13',
        "\x14" => '=14',
        "\x15" => '=15',
        "\x16" => '=16',
        "\x17" => '=17',
        "\x18" => '=18',
        "\x19" => '=19',
        "\x1A" => '=1A',
        "\x1B" => '=1B',
        "\x1C" => '=1C',
        "\x1D" => '=1D',
        "\x1E" => '=1E',
        "\x1F" => '=1F',
        "\x7F" => '=7F',
        "\x80" => '=80',
        "\x81" => '=81',
        "\x82" => '=82',
        "\x83" => '=83',
        "\x84" => '=84',
        "\x85" => '=85',
        "\x86" => '=86',
        "\x87" => '=87',
        "\x88" => '=88',
        "\x89" => '=89',
        "\x8A" => '=8A',
        "\x8B" => '=8B',
        "\x8C" => '=8C',
        "\x8D" => '=8D',
        "\x8E" => '=8E',
        "\x8F" => '=8F',
        "\x90" => '=90',
        "\x91" => '=91',
        "\x92" => '=92',
        "\x93" => '=93',
        "\x94" => '=94',
        "\x95" => '=95',
        "\x96" => '=96',
        "\x97" => '=97',
        "\x98" => '=98',
        "\x99" => '=99',
        "\x9A" => '=9A',
        "\x9B" => '=9B',
        "\x9C" => '=9C',
        "\x9D" => '=9D',
        "\x9E" => '=9E',
        "\x9F" => '=9F',
        "\xA0" => '=A0',
        "\xA1" => '=A1',
        "\xA2" => '=A2',
        "\xA3" => '=A3',
        "\xA4" => '=A4',
        "\xA5" => '=A5',
        "\xA6" => '=A6',
        "\xA7" => '=A7',
        "\xA8" => '=A8',
        "\xA9" => '=A9',
        "\xAA" => '=AA',
        "\xAB" => '=AB',
        "\xAC" => '=AC',
        "\xAD" => '=AD',
        "\xAE" => '=AE',
        "\xAF" => '=AF',
        "\xB0" => '=B0',
        "\xB1" => '=B1',
        "\xB2" => '=B2',
        "\xB3" => '=B3',
        "\xB4" => '=B4',
        "\xB5" => '=B5',
        "\xB6" => '=B6',
        "\xB7" => '=B7',
        "\xB8" => '=B8',
        "\xB9" => '=B9',
        "\xBA" => '=BA',
        "\xBB" => '=BB',
        "\xBC" => '=BC',
        "\xBD" => '=BD',
        "\xBE" => '=BE',
        "\xBF" => '=BF',
        "\xC0" => '=C0',
        "\xC1" => '=C1',
        "\xC2" => '=C2',
        "\xC3" => '=C3',
        "\xC4" => '=C4',
        "\xC5" => '=C5',
        "\xC6" => '=C6',
        "\xC7" => '=C7',
        "\xC8" => '=C8',
        "\xC9" => '=C9',
        "\xCA" => '=CA',
        "\xCB" => '=CB',
        "\xCC" => '=CC',
        "\xCD" => '=CD',
        "\xCE" => '=CE',
        "\xCF" => '=CF',
        "\xD0" => '=D0',
        "\xD1" => '=D1',
        "\xD2" => '=D2',
        "\xD3" => '=D3',
        "\xD4" => '=D4',
        "\xD5" => '=D5',
        "\xD6" => '=D6',
        "\xD7" => '=D7',
        "\xD8" => '=D8',
        "\xD9" => '=D9',
        "\xDA" => '=DA',
        "\xDB" => '=DB',
        "\xDC" => '=DC',
        "\xDD" => '=DD',
        "\xDE" => '=DE',
        "\xDF" => '=DF',
        "\xE0" => '=E0',
        "\xE1" => '=E1',
        "\xE2" => '=E2',
        "\xE3" => '=E3',
        "\xE4" => '=E4',
        "\xE5" => '=E5',
        "\xE6" => '=E6',
        "\xE7" => '=E7',
        "\xE8" => '=E8',
        "\xE9" => '=E9',
        "\xEA" => '=EA',
        "\xEB" => '=EB',
        "\xEC" => '=EC',
        "\xED" => '=ED',
        "\xEE" => '=EE',
        "\xEF" => '=EF',
        "\xF0" => '=F0',
        "\xF1" => '=F1',
        "\xF2" => '=F2',
        "\xF3" => '=F3',
        "\xF4" => '=F4',
        "\xF5" => '=F5',
        "\xF6" => '=F6',
        "\xF7" => '=F7',
        "\xF8" => '=F8',
        "\xF9" => '=F9',
        "\xFA" => '=FA',
        "\xFB" => '=FB',
        "\xFC" => '=FC',
        "\xFD" => '=FD',
        "\xFE" => '=FE',
        "\xFF" => '=FF',
    ];

    // @codingStandardsIgnoreStart
    private const string QP_KEYS_STRING = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8A\x8B\x8C\x8D\x8E\x8F\x90\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9A\x9B\x9C\x9D\x9E\x9F\xA0\xA1\xA2\xA3\xA4\xA5\xA6\xA7\xA8\xA9\xAA\xAB\xAC\xAD\xAE\xAF\xB0\xB1\xB2\xB3\xB4\xB5\xB6\xB7\xB8\xB9\xBA\xBB\xBC\xBD\xBE\xBF\xC0\xC1\xC2\xC3\xC4\xC5\xC6\xC7\xC8\xC9\xCA\xCB\xCC\xCD\xCE\xCF\xD0\xD1\xD2\xD3\xD4\xD5\xD6\xD7\xD8\xD9\xDA\xDB\xDC\xDD\xDE\xDF\xE0\xE1\xE2\xE3\xE4\xE5\xE6\xE7\xE8\xE9\xEA\xEB\xEC\xED\xEE\xEF\xF0\xF1\xF2\xF3\xF4\xF5\xF6\xF7\xF8\xF9\xFA\xFB\xFC\xFD\xFE\xFF";

    // @codingStandardsIgnoreEnd

    /**
     * Check if the given string is "printable"
     *
     * Checks that a string contains no unprintable characters. If this returns
     * false, encode the string for secure delivery.
     *
     * @param string $str
     * @return bool
     */
    public static function isPrintable(string $str): bool
    {
        return strcspn($str, self::QP_KEYS_STRING) === strlen($str);
    }

    /**
     * Encode text as quoted-printable with its line breaks written as hard
     * line breaks, as RFC 2045 section 6.7 requires for text.
     *
     * CRLF, CR and LF all count as line breaks, and each line is wrapped
     * with soft line breaks on its own, keeping trailing whitespace.
     */
    public static function encodeQuotedPrintableText(
        string $text,
        int $lineLength = self::LINELENGTH,
        string $lineEnd = self::LINEEND,
    ): string {
        $encoded = self::encodeQuotedPrintableCharacters(str_replace(
            search: ["\r\n", "\r"],
            replace: "\n",
            subject: $text,
        ));

        return implode($lineEnd, array_map(
            static fn(string $line): string => self::wrapQuotedPrintable($line, $lineLength, $lineEnd),
            explode(
                separator: '=0A',
                string: $encoded,
            ),
        ));
    }

    /**
     * Encode a given string with the QUOTED_PRINTABLE mechanism and wrap the lines.
     *
     * @param string $str
     * @param int $lineLength Defaults to {@link LINELENGTH}
     * @param string $lineEnd Defaults to {@link LINEEND}
     * @return string
     */
    public static function encodeQuotedPrintable(
        string $str,
        int $lineLength = self::LINELENGTH,
        string $lineEnd = self::LINEEND,
    ): string {
        return self::wrapQuotedPrintable(self::encodeQuotedPrintableCharacters($str), $lineLength, $lineEnd);
    }

    /**
     * Wrap quoted-printable text with soft line breaks, never inside an encoded character.
     */
    private static function wrapQuotedPrintable(string $str, int $lineLength, string $lineEnd): string
    {
        $out = '';

        // Split encoded text into separate lines
        $initialPtr = 0;
        $strLength  = strlen($str);
        while ($initialPtr < $strLength) {
            $continueAt = $lineLength;
            $chunk      = substr($str, $initialPtr, $continueAt);

            // Ensure we are not splitting across an encoded character
            $endingMarkerPos = strrpos($chunk, '=');
            if (false !== $endingMarkerPos && $endingMarkerPos >= (strlen($chunk) - 2)) {
                $chunk      = substr($chunk, 0, $endingMarkerPos);
                $continueAt = $endingMarkerPos;
            }

            if (ord($chunk[0]) === 0x2E) { // 0x2E is a dot
                $chunk = '=2E' . substr($chunk, 1);
            }

            // A trailing space would be lost; tabs were already encoded as =09
            if (str_ends_with($chunk, ' ')) {
                $chunk = substr($chunk, offset: 0, length: -1) . '=20';
            }

            // Add string and continue
            $out        .= "{$chunk}={$lineEnd}";
            $initialPtr += $continueAt;
        }

        $out = rtrim($out, $lineEnd);
        return rtrim($out, '=');
    }

    /**
     * Converts a string into quoted printable format.
     *
     * @param  string $str
     * @return string
     */
    // @codingStandardsIgnoreStart
    private static function encodeQuotedPrintableCharacters(string $str): string
    {
        // @codingStandardsIgnoreEnd
        return strtr($str, self::QP_MAP);
    }

    /**
     * Encode a given string with the QUOTED_PRINTABLE mechanism for Mail Headers.
     *
     * Mail headers depend on an extended quoted printable algorithm otherwise
     * a range of bugs can occur.
     *
     * @param string            $str
     * @param string            $charset
     * @param int               $lineLength       Defaults to {@link LINELENGTH}
     * @param string            $lineEnd          Defaults to {@link LINEEND}
     * @param positive-int|0    $headerNameSize   When folding a line, it is necessary to calculate
     *                                            the length of the entire line (together with the header name).
     *                                            Therefore, you can specify the header name and colon length
     *                                            in this argument to fold the string properly.
     * @return string
     */
    public static function encodeQuotedPrintableHeader(
        string $str,
        string $charset,
        int $lineLength = self::LINELENGTH,
        string $lineEnd = self::LINEEND,
        int $headerNameSize = 0,
    ): string {
        // Reduce line-length by the length of the required delimiter, charsets and encoding
        $prefix     = sprintf('=?%s?Q?', $charset);
        $lineLength = $lineLength - strlen($prefix) - 3;

        $str = rtrim(self::encodeQuotedPrintableCharacters($str));

        // Mail-Header required chars have to be encoded also:
        $str = str_replace(['?', ',', ' ', '_'], ['=3F', '=2C', '=20', '=5F'], $str);

        // initialize first line, we need it anyways
        $lines = [0 => ''];

        // Split encoded text into separate lines
        $tmp = '';
        while (strlen($str) > 0) {
            $currentLine = count($lines) - 1;
            $token       = self::getNextQuotedPrintableToken($str);
            $substr      = substr($str, strlen($token));
            $str         = false === $substr ? '' : $substr;

            $tmp .= $token;
            if ('=20' === $token) {
                // only if we have a single char token or space, we can append the
                // tempstring it to the current line or start a new line if necessary.
                $line              = $lines[$currentLine] ?? '';
                $currentLineLength = strlen($line) + strlen($tmp);
                if (0 === $currentLine) {
                    // The size of the first line should be calculated with the header name.
                    $currentLineLength += $headerNameSize;
                }

                $lineLimitReached = $currentLineLength > $lineLength;
                $noCurrentLine    = '' === $line;
                if ($noCurrentLine && $lineLimitReached) {
                    $lines[$currentLine]     = $tmp;
                    $lines[$currentLine + 1] = '';
                } elseif ($lineLimitReached) {
                    $lines[$currentLine + 1] = $tmp;
                } else {
                    $lines[$currentLine] = $line . $tmp;
                }
                $tmp = '';
            }
            // don't forget to append the rest to the last line
            if (strlen($str) === 0) {
                $lines[$currentLine] .= $tmp;
            }
        }

        // assemble the lines together by pre- and appending delimiters, charset, encoding.
        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            $lines[$i] = " {$prefix}{$lines[$i]}?=";
        }
        return trim(implode($lineEnd, $lines));
    }

    /**
     * Retrieves the first token from a quoted printable string.
     *
     * @param  string $str
     * @return string
     */
    private static function getNextQuotedPrintableToken(string $str): string
    {
        if (str_starts_with($str, '=')) {
            $token = substr($str, 0, 3);
        } else {
            $token = substr($str, 0, 1);
        }
        return $token;
    }

    /**
     * Encode a given string in mail header compatible base64 encoding.
     *
     * A UTF-8 string is split between characters, so that each encoded word
     * holds only whole characters (RFC 2047, section 5); a string in another
     * charset is split between any bytes.
     *
     * @param string $str
     * @param string $charset
     * @param int $lineLength Defaults to {@link LINELENGTH}
     * @param string $lineEnd Defaults to {@link LINEEND}
     * @return string
     */
    public static function encodeBase64Header(
        string $str,
        string $charset,
        int $lineLength = self::LINELENGTH,
        string $lineEnd = self::LINEEND,
    ): string {
        $prefix          = "=?{$charset}?B?";
        $suffix          = '?=';
        $remainingLength = $lineLength - strlen($prefix) - strlen($suffix);
        if ('UTF-8' === strtoupper($charset)) {
            return $prefix . implode("{$suffix}{$lineEnd} {$prefix}", array_map(
                base64_encode(...),
                Utf8::chunk($str, maxBytes: intdiv($remainingLength, num2: 4) * 3),
            )) . $suffix;
        }

        $encodedValue = self::encodeBase64($str, $remainingLength, $lineEnd);
        $encodedValue = str_replace($lineEnd, "{$suffix}{$lineEnd} {$prefix}", $encodedValue);
        return $prefix . $encodedValue . $suffix;
    }

    /**
     * Encode a given string in base64 encoding and break lines
     * according to the maximum linelength.
     *
     * @param string $str
     * @param int $lineLength Defaults to {@link LINELENGTH}
     * @param string $lineEnd Defaults to {@link LINEEND}
     * @return string
     */
    public static function encodeBase64(
        string $str,
        int $lineLength = self::LINELENGTH,
        string $lineEnd = self::LINEEND,
    ): string {
        $lineLength -= $lineLength % 4;
        return rtrim(chunk_split(base64_encode($str), $lineLength, $lineEnd));
    }

    // phpcs:disable WebimpressCodingStandard.NamingConventions.ValidVariableName.NotCamelCaps

    /**
     * Apply a Content-Transfer-Encoding; 7bit, 8bit and binary content is returned as it is.
     *
     * @param bool $text Whether the content is text, whose line breaks quoted-printable writes as hard line breaks.
     */
    public static function encode(
        string $str,
        TransferEncoding $encoding,
        string $eol = self::LINEEND,
        bool $text = false,
    ): string {
        return match (true) {
            TransferEncoding::Base64 === $encoding => self::encodeBase64($str, self::LINELENGTH, $eol),
            TransferEncoding::QuotedPrintable === $encoding && $text => self::encodeQuotedPrintableText(
                $str,
                self::LINELENGTH,
                $eol,
            ),
            TransferEncoding::QuotedPrintable === $encoding => self::encodeQuotedPrintable(
                $str,
                self::LINELENGTH,
                $eol,
            ),
            default => $str,
        };
    }

    /**
     * Detect MIME charset
     *
     * Extract parts according to https://tools.ietf.org/html/rfc2047#section-2
     *
     * @param string $str
     * @return string
     */
    public static function mimeDetectCharset(string $str): string
    {
        $matches = [];
        if (1 === preg_match(self::CHARSET_REGEX, $str, $matches)) {
            return strtoupper($matches['charset'] ?? '');
        }

        return 'ASCII';
    }
}
