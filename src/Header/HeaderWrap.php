<?php

namespace Contenir\Mail\Header;

use Contenir\Mail\Headers;
use Contenir\Mail\Mime\Mime;

use function base64_decode;
use function explode;
use function iconv;
use function iconv_mime_decode;
use function iconv_mime_encode;
use function implode;
use function preg_match_all;
use function quoted_printable_decode;
use function str_replace;
use function str_contains;
use function str_pad;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtoupper;
use function substr;
use function trim;
use function wordwrap;

use const ICONV_MIME_DECODE_CONTINUE_ON_ERROR;
use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;

/**
 * Utility class used for creating wrapped or MIME-encoded versions of header
 * values.
 */
// phpcs:ignore WebimpressCodingStandard.NamingConventions.AbstractClass.Prefix
abstract class HeaderWrap
{
    /**
     * Wrap a long header line
     *
     * @param  string          $value
     * @return string
     */
    public static function wrap($value, HeaderInterface $header)
    {
        if ($header instanceof UnstructuredInterface) {
            return static::wrapUnstructuredHeader($value, $header);
        } elseif ($header instanceof StructuredInterface) {
            return static::wrapStructuredHeader($value, $header);
        }
        return $value;
    }

    /**
     * Wrap an unstructured header line
     *
     * Wrap at 78 characters or before, based on whitespace.
     *
     * @param string          $value
     * @return string
     */
    protected static function wrapUnstructuredHeader($value, HeaderInterface $header)
    {
        $headerNameColonSize = strlen($header->getFieldName() . ': ');
        $encoding            = $header->getEncoding();

        if ($encoding == 'ASCII') {
            /*
             * Before folding the header line, it is necessary to calculate the length of the
             * entire header (including the name and colon). We need to put a stub at the
             * beginning of the value so that the folding is performed correctly.
             */
            $headerLine       = str_pad('0', $headerNameColonSize, '0') . $value;
            $foldedHeaderLine = wordwrap($headerLine, 78, Headers::FOLDING);

            // Remove the stub and return the header folded value.
            return substr($foldedHeaderLine, $headerNameColonSize);
        }

        return static::mimeEncodeValue($value, $encoding, 78, $headerNameColonSize);
    }

    /**
     * Wrap a structured header line
     *
     * @param  string              $value
     * @return string
     */
    protected static function wrapStructuredHeader($value, StructuredInterface $header)
    {
        $delimiter = $header->getDelimiter();

        $length = strlen($value);
        $lines  = [];
        $temp   = '';
        for ($i = 0; $i < $length; $i++) {
            $temp .= $value[$i];
            if ($value[$i] == $delimiter) {
                $lines[] = $temp;
                $temp    = '';
            }
        }
        return implode(Headers::FOLDING, $lines);
    }

    /**
     * MIME-encode a value
     *
     * Performs quoted-printable encoding on a value, setting maximum
     * line-length to 998.
     *
     * @param string            $value
     * @param string            $encoding
     * @param int               $lineLength         Maximum line-length, by default 998
     * @param positive-int|0    $firstLineGapSize   When folding a line, it is necessary to calculate
     *                                              the length of the entire line (together with the
     *                                              header name). Therefore, you can specify the header
     *                                              name and colon length in this argument to fold the
     *                                              string properly.
     * @return string Returns the mime encode value without the last line ending
     */
    public static function mimeEncodeValue($value, $encoding, $lineLength = 998, $firstLineGapSize = 0)
    {
        return Mime::encodeQuotedPrintableHeader($value, $encoding, $lineLength, Headers::EOL, $firstLineGapSize);
    }

    /**
     * MIME-decode a value
     *
     * Performs quoted-printable decoding on a value.
     *
     * @param  string $value
     * @return string Returns the mime encode value without the last line ending
     */
    public static function mimeDecodeValue($value)
    {
        // unfold first, because iconv_mime_decode is discarding "\n" with no apparent reason
        // making the resulting value no longer valid.

        // see https://tools.ietf.org/html/rfc2822#section-2.2.3 about unfolding
        $parts = explode(Headers::FOLDING, $value);
        $value = implode(' ', $parts);

        $decodedValue = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        // iconv cannot decode a multibyte character split across adjacent encoded words
        if (self::isNotDecoded($value, $decodedValue)) {
            return self::decodeEncodedWords($value);
        }

        return $decodedValue;
    }

    /**
     * Decode RFC 2047 encoded words, joining adjacent words of the same charset
     * before conversion so that multibyte characters split across them survive.
     */
    private static function decodeEncodedWords(string $value): string
    {
        $pattern = '/=\?([^?*]+)(?:\*[^?]*)?\?([BbQq])\?([^?]*)\?=/';
        if (! preg_match_all($pattern, $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $value;
        }

        $result  = '';
        $buffer  = '';
        $charset = null;
        $offset  = 0;

        foreach ($matches as $match) {
            $between = substr($value, $offset, $match[0][1] - $offset);
            $offset  = $match[0][1] + strlen($match[0][0]);

            // Whitespace between adjacent encoded words is not displayed (RFC 2047, section 6.2)
            if ($charset === null || trim($between) !== '') {
                $result .= self::convertToUtf8($buffer, $charset) . $between;
                $buffer  = '';
                $charset = null;
            }

            $wordCharset = strtoupper($match[1][0]);
            if ($charset !== null && $wordCharset !== $charset) {
                $result .= self::convertToUtf8($buffer, $charset);
                $buffer  = '';
            }

            $charset = $wordCharset;
            $buffer .= strtoupper($match[2][0]) === 'B'
                ? (string) base64_decode($match[3][0])
                : quoted_printable_decode(str_replace('_', ' ', $match[3][0]));
        }

        return $result . self::convertToUtf8($buffer, $charset) . substr($value, $offset);
    }

    private static function convertToUtf8(string $value, ?string $charset): string
    {
        if ($value === '' || $charset === null || $charset === 'UTF-8') {
            return $value;
        }

        $converted = iconv($charset, 'UTF-8', $value);

        return $converted === false ? $value : $converted;
    }

    private static function isNotDecoded(string $originalValue, string $value): bool
    {
        return str_starts_with($value, '=?')
            && strlen($value) - 2 === strpos($value, '?=')
            && str_contains($originalValue, $value);
    }

    /**
     * Test if is possible apply MIME-encoding
     *
     * @param string $value
     * @return bool
     */
    public static function canBeEncoded($value)
    {
        // avoid any wrapping by specifying line length long enough
        // "test" -> 4
        // "x-test: =?ISO-8859-1?B?dGVzdA==?=" -> 33
        //  8       +2          +3         +3  -> 16
        $charset    = 'UTF-8';
        $lineLength = strlen($value) * 4 + strlen($charset) + 16;

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
