<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Headers;

use function array_key_exists;
use function count;
use function is_numeric;
use function mb_str_split;
use function rtrim;
use function sprintf;
use function str_contains;
use function strlen;
use function strpos;
use function substr;

/**
 * RFC 2231 parameter value continuations: filename*0="...", filename*1="...".
 *
 * @internal Used by ContentDisposition.
 */
final class ParameterContinuation
{
    private function __construct() {}

    /**
     * Reassemble continued parameters; other parameters pass through unchanged.
     *
     * @param list<array{string, string}> $parameters name and value pairs, in header order
     * @return array<string, string>
     * @throws Exception\InvalidArgumentException When a section number is not numeric or a section is missing.
     */
    public static function join(array $parameters, string $headerLine): array
    {
        $joined    = [];
        $continued = [];
        foreach ($parameters as [$name, $value]) {
            if (! str_contains($name, '*')) {
                $joined[$name] = $value;
                continue;
            }

            // A trailing "*" marks an extended value (filename*0*=UTF-8''...)
            $position = (int) strpos($name, needle: '*');
            $section  = rtrim(substr($name, $position + 1), characters: '*');
            $name     = substr($name, offset: 0, length: $position);
            // The section number is optional: filename*=UTF-8''%64%61%61%6D%69.jpg
            $section = '' === $section ? '0' : $section;
            if (! is_numeric($section)) {
                throw new Exception\InvalidArgumentException(sprintf(
                    'Invalid header line for Content-Disposition string - count expected to be numeric, got "%s"',
                    $section,
                ));
            }

            $continued[$name][(int) $section] = $value;
        }

        foreach ($continued as $name => $sections) {
            $value = '';
            for ($i = 0, $total = count($sections); $i < $total; ++$i) {
                if (! array_key_exists($i, $sections)) {
                    throw new Exception\InvalidArgumentException(
                        "Invalid header line for Content-Disposition string - incomplete continuation; HeaderLine: {$headerLine}",
                    );
                }

                $value .= $sections[$i];
            }

            $joined[$name] = $value;
        }

        return $joined;
    }

    /**
     * Split a long parameter into continuation sections, each on its own folded line.
     */
    public static function split(string $attribute, string $value): string
    {
        return self::splitWith($attribute, $value, static fn(string $section): string => $section);
    }

    /**
     * As split(), RFC 2047 encoding every section, even those that happen to
     * be plain ASCII, so the parts decode alike.
     */
    public static function splitEncoded(string $attribute, string $value): string
    {
        return self::splitWith(
            $attribute,
            $value,
            HeaderWrap::mimeEncodeValue(...),
        );
    }

    /**
     * Fill each section with as many characters as fit on its line, and at
     * least one, so a long parameter name can never stall the split.
     *
     * @param callable(string): string $encode
     */
    private static function splitWith(string $attribute, string $value, callable $encode): string
    {
        $sections = [];
        $current  = '';
        foreach (mb_str_split($value, encoding: 'UTF-8') as $character) {
            $prefix    = sprintf('%s*%d="', $attribute, count($sections));
            $candidate = $current . $character;
            if (
                '' !== $current
                && (strlen($prefix) + strlen($encode($candidate))) >= ContentDisposition::MAX_PARAMETER_LENGTH
            ) {
                $sections[] = $current;
                $candidate  = $character;
            }

            $current = $candidate;
        }

        if ('' !== $current) {
            $sections[] = $current;
        }

        $result = '';
        foreach ($sections as $index => $section) {
            $result .= sprintf(';%s%s*%d="%s"', Headers::FOLDING, $attribute, $index, $encode($section));
        }

        return $result;
    }
}
