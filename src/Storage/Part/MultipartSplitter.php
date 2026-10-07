<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Part;

use Contenir\Mail\Storage\Exception;

use function count;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Splits multipart bodies into their parts, reading line by line so memory
 * stays bounded whatever the size of the input.
 *
 * Hostile input is cut short: a multipart holds at most MAX_PARTS parts,
 * and a missing closing boundary ends the last part at the end of the body
 * rather than failing.
 *
 * @internal Used by Storage\Part.
 */
final class MultipartSplitter
{
    /** Most parts one multipart may hold */
    public const int MAX_PARTS = 1000;

    /**
     * The parts of a multipart body, between "--boundary" lines.
     *
     * The preamble before the first boundary and the epilogue after the
     * closing one are not parts. The line break before a boundary line
     * belongs to the boundary (RFC 2046, section 5.1.1).
     *
     * @return list<Content>
     * @throws Exception\RuntimeException When there are more than MAX_PARTS parts.
     */
    public static function split(Content $body, string $boundary): array
    {
        $delimiter = "--{$boundary}";
        $parts     = [];
        $partStart = null;
        $lineStart = true;
        $previous  = '';
        foreach ($body->lines() as $offset => $piece) {
            $kind      = $lineStart ? self::delimiterKind($piece, $delimiter) : null;
            $lineStart = Content::endsLine($piece);
            if (null === $kind) {
                $previous = $piece;
                continue;
            }

            if (null !== $partStart) {
                $parts[] = $body->slice($partStart, $offset - self::lineBreakLength($previous));
                if (count($parts) > self::MAX_PARTS) {
                    throw new Exception\RuntimeException(sprintf(
                        'A multipart may hold at most %d parts',
                        self::MAX_PARTS,
                    ));
                }
            }

            if ('close' === $kind) {
                return $parts;
            }

            $partStart = $offset + strlen($piece);
            $previous  = $piece;
        }

        if (null !== $partStart) {
            $parts[] = $body->slice($partStart, $body->length());
        }

        return $parts;
    }

    /**
     * "part" for a boundary line, "close" for the closing one, null for any other line.
     */
    private static function delimiterKind(string $line, string $delimiter): ?string
    {
        if (! str_starts_with($line, $delimiter)) {
            return null;
        }

        $rest = rtrim(substr($line, strlen($delimiter)), characters: " \t\r\n");

        return match ($rest) {
            ''      => 'part',
            '--'    => 'close',
            default => null,
        };
    }

    /**
     * The length of the line break ending the line before a boundary line, which always has one.
     */
    private static function lineBreakLength(string $previous): int
    {
        return str_ends_with($previous, "\r\n") ? 2 : 1;
    }
}
