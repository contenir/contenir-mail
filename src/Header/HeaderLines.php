<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Exception\RuntimeException;
use Contenir\Mail\Headers;

use function explode;
use function implode;
use function str_replace;
use function trim;

/**
 * Physical lines of a header block: split on reading, and joined back into a header's written text.
 *
 * @internal Used by HeaderBlock.
 */
final class HeaderLines
{
    /**
     * The lines that carry text, and lines of only whitespace. A blank line
     * ends the headers, so text after more than one blank line, or more than
     * two blank lines, is malformed.
     *
     * A CR before the line break is dropped when CRLF text is split on LF.
     *
     * @return list<string>
     * @throws RuntimeException
     */
    public static function split(string $block, string $eol): array
    {
        $lines      = [];
        $emptyLines = 0;
        if (Headers::EOL !== $eol) {
            $block = str_replace("\r{$eol}", $eol, $block);
        }

        foreach (explode($eol, $block) as $line) {
            $hasText    = '' !== trim($line);
            $emptyLines += '' === $line ? 1 : 0;
            if ($emptyLines > 2 || ($emptyLines > 1 && $hasText)) {
                throw new RuntimeException('Malformed header detected');
            }

            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The written lines of one header joined by CRLF, or null when a line
     * cannot be written back as it is: one holding a bare CR or LF, or a byte
     * outside US-ASCII.
     *
     * @param list<string> $lines
     */
    public static function join(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (! HeaderValue::isValid($line)) {
                return null;
            }
        }

        return implode(Headers::EOL, $lines);
    }
}
