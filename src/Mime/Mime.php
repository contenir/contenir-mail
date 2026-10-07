<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

use function base64_encode;
use function chunk_split;
use function count;
use function implode;
use function max;
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
use function substr;
use function trim;

/**
 * Support class for MultiPart Mime Messages
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
    public const string CHARSET_REGEX            = '#=\?(?P<charset>[\x21\x23-\x26\x2a\x2b\x2d\x5e\5f\60\x7b-\x7ea-zA-Z0-9]+)\?(?P<encoding>[\x21\x23-\x26\x2a\x2b\x2d\x5e\5f\60\x7b-\x7ea-zA-Z0-9]+)\?(?P<text>[\x21-\x3e\x40-\x7e]+)#';

    // phpcs:enable

    /**
     * Lookup-tables for QuotedPrintable
     *
     * @var string[]
     */
    private function __construct() {}

    private const array QP_KEYS = [
        "\x00",
        "\x01",
        "\x02",
        "\x03",
        "\x04",
        "\x05",
        "\x06",
        "\x07",
        "\x08",
        "\x09",
        "\x0A",
        "\x0B",
        "\x0C",
        "\x0D",
        "\x0E",
        "\x0F",
        "\x10",
        "\x11",
        "\x12",
        "\x13",
        "\x14",
        "\x15",
        "\x16",
        "\x17",
        "\x18",
        "\x19",
        "\x1A",
        "\x1B",
        "\x1C",
        "\x1D",
        "\x1E",
        "\x1F",
        "\x7F",
        "\x80",
        "\x81",
        "\x82",
        "\x83",
        "\x84",
        "\x85",
        "\x86",
        "\x87",
        "\x88",
        "\x89",
        "\x8A",
        "\x8B",
        "\x8C",
        "\x8D",
        "\x8E",
        "\x8F",
        "\x90",
        "\x91",
        "\x92",
        "\x93",
        "\x94",
        "\x95",
        "\x96",
        "\x97",
        "\x98",
        "\x99",
        "\x9A",
        "\x9B",
        "\x9C",
        "\x9D",
        "\x9E",
        "\x9F",
        "\xA0",
        "\xA1",
        "\xA2",
        "\xA3",
        "\xA4",
        "\xA5",
        "\xA6",
        "\xA7",
        "\xA8",
        "\xA9",
        "\xAA",
        "\xAB",
        "\xAC",
        "\xAD",
        "\xAE",
        "\xAF",
        "\xB0",
        "\xB1",
        "\xB2",
        "\xB3",
        "\xB4",
        "\xB5",
        "\xB6",
        "\xB7",
        "\xB8",
        "\xB9",
        "\xBA",
        "\xBB",
        "\xBC",
        "\xBD",
        "\xBE",
        "\xBF",
        "\xC0",
        "\xC1",
        "\xC2",
        "\xC3",
        "\xC4",
        "\xC5",
        "\xC6",
        "\xC7",
        "\xC8",
        "\xC9",
        "\xCA",
        "\xCB",
        "\xCC",
        "\xCD",
        "\xCE",
        "\xCF",
        "\xD0",
        "\xD1",
        "\xD2",
        "\xD3",
        "\xD4",
        "\xD5",
        "\xD6",
        "\xD7",
        "\xD8",
        "\xD9",
        "\xDA",
        "\xDB",
        "\xDC",
        "\xDD",
        "\xDE",
        "\xDF",
        "\xE0",
        "\xE1",
        "\xE2",
        "\xE3",
        "\xE4",
        "\xE5",
        "\xE6",
        "\xE7",
        "\xE8",
        "\xE9",
        "\xEA",
        "\xEB",
        "\xEC",
        "\xED",
        "\xEE",
        "\xEF",
        "\xF0",
        "\xF1",
        "\xF2",
        "\xF3",
        "\xF4",
        "\xF5",
        "\xF6",
        "\xF7",
        "\xF8",
        "\xF9",
        "\xFA",
        "\xFB",
        "\xFC",
        "\xFD",
        "\xFE",
        "\xFF",
    ];

    /** @var string[] */
    private const array QP_REPLACE_VALUES = [
        '=00',
        '=01',
        '=02',
        '=03',
        '=04',
        '=05',
        '=06',
        '=07',
        '=08',
        '=09',
        '=0A',
        '=0B',
        '=0C',
        '=0D',
        '=0E',
        '=0F',
        '=10',
        '=11',
        '=12',
        '=13',
        '=14',
        '=15',
        '=16',
        '=17',
        '=18',
        '=19',
        '=1A',
        '=1B',
        '=1C',
        '=1D',
        '=1E',
        '=1F',
        '=7F',
        '=80',
        '=81',
        '=82',
        '=83',
        '=84',
        '=85',
        '=86',
        '=87',
        '=88',
        '=89',
        '=8A',
        '=8B',
        '=8C',
        '=8D',
        '=8E',
        '=8F',
        '=90',
        '=91',
        '=92',
        '=93',
        '=94',
        '=95',
        '=96',
        '=97',
        '=98',
        '=99',
        '=9A',
        '=9B',
        '=9C',
        '=9D',
        '=9E',
        '=9F',
        '=A0',
        '=A1',
        '=A2',
        '=A3',
        '=A4',
        '=A5',
        '=A6',
        '=A7',
        '=A8',
        '=A9',
        '=AA',
        '=AB',
        '=AC',
        '=AD',
        '=AE',
        '=AF',
        '=B0',
        '=B1',
        '=B2',
        '=B3',
        '=B4',
        '=B5',
        '=B6',
        '=B7',
        '=B8',
        '=B9',
        '=BA',
        '=BB',
        '=BC',
        '=BD',
        '=BE',
        '=BF',
        '=C0',
        '=C1',
        '=C2',
        '=C3',
        '=C4',
        '=C5',
        '=C6',
        '=C7',
        '=C8',
        '=C9',
        '=CA',
        '=CB',
        '=CC',
        '=CD',
        '=CE',
        '=CF',
        '=D0',
        '=D1',
        '=D2',
        '=D3',
        '=D4',
        '=D5',
        '=D6',
        '=D7',
        '=D8',
        '=D9',
        '=DA',
        '=DB',
        '=DC',
        '=DD',
        '=DE',
        '=DF',
        '=E0',
        '=E1',
        '=E2',
        '=E3',
        '=E4',
        '=E5',
        '=E6',
        '=E7',
        '=E8',
        '=E9',
        '=EA',
        '=EB',
        '=EC',
        '=ED',
        '=EE',
        '=EF',
        '=F0',
        '=F1',
        '=F2',
        '=F3',
        '=F4',
        '=F5',
        '=F6',
        '=F7',
        '=F8',
        '=F9',
        '=FA',
        '=FB',
        '=FC',
        '=FD',
        '=FE',
        '=FF',
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
        $out = '';
        $str = self::encodeQuotedPrintableCharacters($str);

        // Split encoded text into separate lines
        $initialPtr = 0;
        $strLength  = strlen($str);
        while ($initialPtr < $strLength) {
            $continueAt = $strLength - $initialPtr;

            if ($continueAt > $lineLength) {
                $continueAt = $lineLength;
            }

            $chunk = substr($str, $initialPtr, $continueAt);

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
        $str = str_replace('=', '=3D', $str);
        $str = str_replace(self::QP_KEYS, self::QP_REPLACE_VALUES, $str);
        return rtrim($str);
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

        $str = self::encodeQuotedPrintableCharacters($str);

        // Mail-Header required chars have to be encoded also:
        $str = str_replace(['?', ',', ' ', '_'], ['=3F', '=2C', '=20', '=5F'], $str);

        // initialize first line, we need it anyways
        $lines = [0 => ''];

        // Split encoded text into separate lines
        $tmp = '';
        while (strlen($str) > 0) {
            $currentLine = max(count($lines) - 1, 0);
            $token       = self::getNextQuotedPrintableToken($str);
            $substr      = substr($str, strlen($token));
            $str         = false === $substr ? '' : $substr;

            $tmp .= $token;
            if ('=20' === $token) {
                // only if we have a single char token or space, we can append the
                // tempstring it to the current line or start a new line if necessary.
                if (0 === $currentLine) {
                    // The size of the first line should be calculated with the header name.
                    $currentLineLength = strlen($lines[$currentLine] . $tmp) + $headerNameSize;
                } else {
                    $currentLineLength = strlen($lines[$currentLine] . $tmp);
                }

                $lineLimitReached = $currentLineLength > $lineLength;
                $noCurrentLine    = '' === $lines[$currentLine];
                if ($noCurrentLine && $lineLimitReached) {
                    $lines[$currentLine]     = $tmp;
                    $lines[$currentLine + 1] = '';
                } elseif ($lineLimitReached) {
                    $lines[$currentLine + 1] = $tmp;
                } else {
                    $lines[$currentLine] .= $tmp;
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
     */
    public static function encode(string $str, TransferEncoding $encoding, string $eol = self::LINEEND): string
    {
        return match ($encoding) {
            TransferEncoding::Base64          => self::encodeBase64($str, self::LINELENGTH, $eol),
            TransferEncoding::QuotedPrintable => self::encodeQuotedPrintable($str, self::LINELENGTH, $eol),
            default                           => $str,
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
