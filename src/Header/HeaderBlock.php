<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Exception\RuntimeException;

use function count;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Splits a header block into one field per header.
 *
 * @internal Used by HeaderParser.
 */
final class HeaderBlock
{
    /** Most headers one block may hold */
    public const int MAX_HEADERS = 1000;

    /**
     * Split a header block into fields: each header's unfolded line, and its
     * text as it was written, folded lines joined by CRLF.
     *
     * The written text is null when it cannot be written back safely as it
     * is: when a line holds a bare CR or LF or a byte outside US-ASCII, or
     * when a line of only whitespace was dropped from the field.
     *
     * @return list<array{string, string|null}>
     * @throws RuntimeException When a line is neither a header nor a continuation, or the block is too large.
     */
    public static function fields(string $block, string $eol): array
    {
        $fields = [];
        /** @var array{string, list<string>, bool}|null $field unfolded line, written lines, whether those are complete */
        $field = null;
        foreach (HeaderLines::split($block, $eol) as $line) {
            if ('' === trim($line)) {
                $field = null === $field ? null : [$field[0], $field[1], false];
                continue;
            }

            if (1 === preg_match('/^[\x21-\x39\x3B-\x7E]+:/', $line)) {
                $fields[] = $field;
                $field    = [trim($line), [$line], true];
                continue;
            }

            if (null === $field || 1 !== preg_match('/^\s/', $line)) {
                throw new RuntimeException(sprintf('Line "%s" does not match header format!', $line));
            }

            $field = [$field[0] . ' ' . trim($line), [...$field[1], $line], $field[2]];
        }

        $fields[] = $field;

        $result = [];
        foreach ($fields as $complete) {
            if (null === $complete) {
                continue;
            }

            $result[] = [$complete[0], $complete[2] ? HeaderLines::join($complete[1]) : null];
        }

        if (count($result) > self::MAX_HEADERS) {
            throw new RuntimeException(sprintf('A header block may hold at most %d headers', self::MAX_HEADERS));
        }

        return $result;
    }
}
