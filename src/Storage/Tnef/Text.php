<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Tnef;

use Contenir\Mail\CharsetConverter;
use Contenir\Mail\Mime\Mime;
use Contenir\Mail\Utf8;

use function preg_match;
use function strpos;
use function strtolower;
use function substr;

/**
 * Turns the text in a TNEF container into UTF-8, and its media types into ones safe to use.
 *
 * @internal Used by the TNEF reader.
 */
final class Text
{
    /** The charset of 8-bit text when the container names no code page, or one not listed */
    public const string DEFAULT_CHARSET = 'WINDOWS-1252';

    /**
     * Charsets for the Windows code pages 8-bit text is written in.
     *
     * @var array<int, string>
     */
    private const array CODEPAGES = [
        874    => 'TIS-620',
        932    => 'CP932',
        936    => 'GBK',
        949    => 'EUC-KR',
        950    => 'BIG5',
        1250   => 'WINDOWS-1250',
        1251   => 'WINDOWS-1251',
        1252   => 'WINDOWS-1252',
        1253   => 'WINDOWS-1253',
        1254   => 'WINDOWS-1254',
        1255   => 'WINDOWS-1255',
        1256   => 'WINDOWS-1256',
        1257   => 'WINDOWS-1257',
        1258   => 'WINDOWS-1258',
        20_866 => 'KOI8-R',
        28_591 => 'ISO-8859-1',
        65_001 => 'UTF-8',
    ];

    /** A media type the sender gave, kept only when it is a plain type/subtype */
    private const string MEDIA_TYPE = '~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*\z~';

    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

    /**
     * The charset of a Windows code page, Windows-1252 for one not listed.
     */
    public static function charset(int $codepage): string
    {
        return self::CODEPAGES[$codepage] ?? self::DEFAULT_CHARSET;
    }

    /**
     * Text as UTF-8, up to its first NUL, with invalid sequences replaced.
     */
    public static function utf8(string $bytes, string $charset): string
    {
        $text = CharsetConverter::toUtf8($bytes, $charset);
        $end  = strpos($text, needle: "\0");

        return Utf8::scrub(false === $end ? $text : substr($text, offset: 0, length: $end));
    }

    /**
     * The media type in lower case, or application/octet-stream when it is missing or more than a type/subtype.
     */
    public static function mediaType(?string $type): string
    {
        $type = strtolower($type ?? '');

        return 1 === preg_match(self::MEDIA_TYPE, $type) ? $type : Mime::TYPE_OCTETSTREAM;
    }
}
