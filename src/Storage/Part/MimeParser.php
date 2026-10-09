<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Part;

use Contenir\Mail\Exception\RuntimeException as HeaderException;
use Contenir\Mail\Headers;
use Contenir\Mail\Storage\Exception;

use function preg_match;
use function sprintf;
use function str_contains;
use function strlen;
use function trim;

/**
 * Splits stored messages and parts into headers and body, reading line by
 * line so memory stays bounded whatever the size of the input. A header
 * block larger than MAX_HEADER_BYTES is refused.
 *
 * @internal Used by Storage\Part.
 */
final class MimeParser
{
    /** Largest header block read, in bytes */
    public const int MAX_HEADER_BYTES = 1_048_576;

    /**
     * The headers and the body of a message or part. Text that does not
     * start with a header line is all body, under no headers.
     *
     * @return array{Headers, Content}
     * @throws Exception\RuntimeException When the header block is too large or malformed.
     */
    public static function split(Content $raw): array
    {
        $block     = '';
        $lineStart = true;
        foreach (Lines::of($raw) as $offset => $piece) {
            if ($lineStart && '' === trim($piece, characters: "\r\n")) {
                return [self::headers($block), $raw->slice($offset + strlen($piece), $raw->length())];
            }

            if ('' === $block && 1 !== preg_match('/^[\x21-\x39\x3B-\x7E]+:/', $piece)) {
                return [new Headers(), $raw];
            }

            $block     .= $piece;
            $lineStart = Lines::endsLine($piece);
            if (strlen($block) > self::MAX_HEADER_BYTES) {
                throw new Exception\RuntimeException(sprintf(
                    'The header block is larger than %d bytes',
                    self::MAX_HEADER_BYTES,
                ));
            }
        }

        return [self::headers($block), $raw->slice($raw->length(), $raw->length())];
    }

    /**
     * @throws Exception\RuntimeException When the block is malformed.
     */
    private static function headers(string $block): Headers
    {
        try {
            return Headers::fromString($block, str_contains($block, "\r\n") ? Headers::EOL : "\n");
        } catch (HeaderException $e) {
            throw new Exception\RuntimeException("Cannot read the message headers: {$e->getMessage()}", 0, $e);
        }
    }
}
