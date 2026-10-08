<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Exception\RuntimeException;

use function count;
use function preg_match;
use function sprintf;
use function strlen;
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
     * A name longer than HeaderName::MAX_LENGTH leaves no room for even
     * the colon in 998 characters (RFC 5322, section 2.1.1), and no header
     * can hold it, so the block is rejected.
     *
     * @return list<array{string, string|null}>
     * @throws RuntimeException When a line is neither a header nor a continuation, a name is too long, or the block is too large.
     */
    public static function fields(string $block, string $eol): array
    {
        return self::collect($block, $eol, skipMalformed: false);
    }

    /**
     * Split a header block read from a message into fields, as fields() does,
     * but dropping a line that is neither a header nor a continuation, with
     * any continuation lines of its own, as mail clients do. One such line
     * then does not make the whole message unreadable.
     *
     * @return list<array{string, string|null}>
     * @throws RuntimeException When a name is too long, or the block is too large.
     */
    public static function readableFields(string $block, string $eol): array
    {
        return self::collect($block, $eol, skipMalformed: true);
    }

    /**
     * @return list<array{string, string|null}>
     * @throws RuntimeException When a line is neither a header nor a continuation and is not skipped,
     *     a name is too long, or the block is too large.
     *
     * @mago-expect lint:no-boolean-flag-parameter Private; fields() and readableFields() name the two modes.
     */
    private static function collect(string $block, string $eol, bool $skipMalformed): array
    {
        $fields = [];
        /** @var array{string, list<string>, bool}|null $field unfolded line, written lines, whether those are complete */
        $field = null;
        foreach (HeaderLines::split($block, $eol) as $line) {
            if ('' === trim($line)) {
                $field = null === $field ? null : [$field[0], $field[1], false];
                continue;
            }

            $name = [];
            if (1 === preg_match('/^([\x21-\x39\x3B-\x7E]+):/', $line, $name)) {
                self::assertNameLength($name[1] ?? '');
                $fields[] = $field;
                $field    = [trim($line), [$line], true];
                continue;
            }

            if (null === $field || 1 !== preg_match('/^\s/', $line)) {
                if (! $skipMalformed) {
                    throw new RuntimeException(sprintf('Line "%s" does not match header format!', $line));
                }

                $fields[] = $field;
                $field    = null;
                continue;
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

    /**
     * @throws Exception\RuntimeException When the name is longer than HeaderName::MAX_LENGTH.
     */
    private static function assertNameLength(string $name): void
    {
        if (strlen($name) > HeaderName::MAX_LENGTH) {
            throw new Exception\RuntimeException(sprintf(
                'Header name must be at most %d characters',
                HeaderName::MAX_LENGTH,
            ));
        }
    }
}
