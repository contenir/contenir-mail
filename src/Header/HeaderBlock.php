<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Exception\RuntimeException;

use function array_key_last;
use function explode;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Splits a header block into one line per header.
 *
 * @internal Used by HeaderParser.
 */
final class HeaderBlock
{
    private function __construct() {}

    /**
     * Split a header block into complete header lines, joining continuation
     * lines (RFC 5322, section 2.2.3).
     *
     * @return list<string>
     * @throws RuntimeException When a line is neither a header nor a continuation.
     */
    public static function lines(string $block, string $eol): array
    {
        $lines = [];
        foreach (self::contentLines($block, $eol) as $line) {
            if (1 === preg_match('/^[\x21-\x39\x3B-\x7E]+:/', $line)) {
                $lines[] = trim($line);
                continue;
            }

            $last = array_key_last($lines);
            if (null === $last || 1 !== preg_match('/^\s/', $line)) {
                throw new RuntimeException(sprintf('Line "%s" does not match header format!', $line));
            }

            $lines[$last] = ($lines[$last] ?? '') . ' ' . trim($line);
        }

        return $lines;
    }

    /**
     * The lines that carry text. A blank line ends the headers, so text after
     * more than one blank line, or more than two blank lines, is malformed.
     *
     * @return list<string>
     * @throws RuntimeException
     */
    private static function contentLines(string $block, string $eol): array
    {
        $lines      = [];
        $emptyLines = 0;
        foreach (explode($eol, $block) as $line) {
            $hasText    = '' !== trim($line);
            $emptyLines += '' === $line ? 1 : 0;
            if ($emptyLines > 2 || ($emptyLines > 1 && $hasText)) {
                throw new RuntimeException('Malformed header detected');
            }

            if ($hasText) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
