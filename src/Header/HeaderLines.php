<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Exception\RuntimeException;
use Contenir\Mail\Headers;

use function explode;
use function implode;
use function sprintf;
use function str_replace;
use function strlen;
use function trim;

/**
 * Physical lines of a header block: split on reading, and joined back into a header's written text.
 *
 * @internal Used by HeaderBlock.
 */
final class HeaderLines
{
    /** Largest header block read, in bytes */
    public const int MAX_BLOCK_BYTES = 1_048_576;

    /** Longest line written back as it was read, without its CRLF (RFC 5322, section 2.1.1) */
    public const int MAX_LINE_LENGTH = 998;

    /**
     * The lines that carry text, and lines of only whitespace. A blank line
     * ends the headers, so text after more than one blank line, or more than
     * two blank lines, is malformed.
     *
     * A CR before the line break is dropped when CRLF text is split on LF.
     *
     * @return list<string>
     * @throws RuntimeException When the block is malformed or larger than MAX_BLOCK_BYTES.
     */
    public static function split(string $block, string $eol): array
    {
        if (strlen($block) > self::MAX_BLOCK_BYTES) {
            throw new RuntimeException(sprintf('A header block may be at most %d bytes', self::MAX_BLOCK_BYTES));
        }

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
     * cannot be written back as it is: one holding a bare CR or LF, a control character or a byte
     * outside US-ASCII, or one longer than RFC 5322 allows.
     *
     * @param list<string> $lines
     */
    public static function join(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (strlen($line) > self::MAX_LINE_LENGTH || ! HeaderValue::isValid($line)) {
                return null;
            }
        }

        return implode(Headers::EOL, $lines);
    }
}
