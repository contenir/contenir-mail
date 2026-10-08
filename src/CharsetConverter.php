<?php

declare(strict_types=1);

namespace Contenir\Mail;

use Contenir\Mail\Protocol\ErrorCapture;

use function iconv;
use function in_array;
use function strtoupper;

/**
 * Converts text in a charset mail is written in to UTF-8.
 *
 * @internal
 */
final class CharsetConverter
{
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

    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

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
}
