<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Part;

use Contenir\Mail\Storage\Exception;
use Generator;

use function str_ends_with;
use function strlen;
use function strpos;
use function strrpos;
use function substr;

/**
 * Content read line by line, from the blocks it is read in.
 *
 * @internal Used by Storage\Part.
 */
final class Lines
{
    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

    /**
     * The content in pieces of at most Content::CHUNK bytes, each ending at
     * a line break or at the chunk size, keyed by their offset from the start.
     *
     * A piece starts a line when the piece before it ended with "\n". A
     * stream that ends early, as a file cut short since it was read, ends
     * the pieces there.
     *
     * @return Generator<int, string>
     * @throws Exception\RuntimeException When the storage has been closed, or the file cannot be opened.
     */
    public static function of(Content $content): Generator
    {
        $buffer   = '';
        $position = 0;
        $offset   = 0;
        foreach ($content->blocks() as $block) {
            $offset   += $position;
            $buffer   = substr($buffer, $position) . $block;
            $position = 0;
            while (null !== ($length = self::pieceLength($buffer, $position))) {
                yield $offset + $position => substr($buffer, $position, $length);

                $position += $length;
            }
        }

        if ($position < strlen($buffer)) {
            yield $offset + $position => substr($buffer, $position);
        }
    }

    /**
     * Whether a piece from of() ends a line.
     */
    public static function endsLine(string $piece): bool
    {
        return str_ends_with($piece, "\n");
    }

    /**
     * Blocks rejoined to end at line breaks, the last with what follows the last line break.
     *
     * @param iterable<string> $blocks
     * @return Generator<int, string>
     */
    public static function whole(iterable $blocks): Generator
    {
        $carry = '';
        foreach ($blocks as $block) {
            $break = strrpos($block, needle: "\n");
            if (false === $break) {
                $carry .= $block;
                continue;
            }

            yield $carry . substr($block, offset: 0, length: $break + 1);

            $carry = substr($block, $break + 1);
        }

        yield $carry;
    }

    /**
     * The length of the piece at $position, or null when the bytes after it
     * may still continue it: they hold no line break and are shorter than a chunk.
     */
    private static function pieceLength(string $buffer, int $position): ?int
    {
        $break = strpos($buffer, needle: "\n", offset: $position);
        if (false !== $break && ($break - $position) < Content::CHUNK) {
            return $break - $position + 1;
        }

        return (strlen($buffer) - $position) >= Content::CHUNK ? Content::CHUNK : null;
    }
}
