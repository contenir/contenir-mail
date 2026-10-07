<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Headers;
use Contenir\Mail\Mime\Mime;

use function count;
use function implode;
use function sprintf;
use function trim;

/**
 * Parameter lists in structured headers: `type/subtype; name="value"; ...`.
 *
 * @internal Used by ContentType and ContentDisposition.
 */
final class HeaderParameters
{
    /** Characters trimmed from parameter names and values */
    private const string TRIM = "'\" \t\n\r\0\x0B";

    private function __construct() {}

    /**
     * Split `name="value"; name2=value2` into name and value pairs, in order.
     *
     * @return list<array{string, string}>
     */
    public static function parse(string $parameters): array
    {
        $values = ListParser::parse(trim($parameters), [';', '=']);
        $pairs  = [];
        for ($i = 0, $length = count($values); $i < $length; $i += 2) {
            $pairs[] = [
                trim($values[$i] ?? '', characters: self::TRIM),
                trim($values[$i + 1] ?? '', characters: self::TRIM),
            ];
        }

        return $pairs;
    }

    /**
     * The value followed by its parameters on one line, as a reader would see it.
     *
     * @param array<string, string> $parameters
     */
    public static function join(string $value, array $parameters): string
    {
        return implode('; ', self::lines($value, $parameters));
    }

    /**
     * As join(), each parameter on its own folded line, with values that are not ASCII RFC 2047 encoded.
     *
     * @param array<string, string> $parameters
     */
    public static function joinEncoded(string $fieldName, string $value, array $parameters): string
    {
        $encoded = [];
        foreach ($parameters as $name => $parameter) {
            $encoded[$name] = Mime::isPrintable($parameter) ? $parameter : HeaderWrap::fold($fieldName, $parameter);
        }

        return implode(';' . Headers::FOLDING, self::lines($value, $encoded));
    }

    /**
     * @param array<string, string> $parameters
     * @return list<string>
     */
    private static function lines(string $value, array $parameters): array
    {
        $lines = [$value];
        foreach ($parameters as $name => $parameter) {
            $lines[] = sprintf('%s="%s"', $name, $parameter);
        }

        return $lines;
    }
}
