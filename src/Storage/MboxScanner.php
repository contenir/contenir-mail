<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\Storage\Part\Content;

use function fclose;
use function fgets;
use function fopen;
use function fstat;
use function max;
use function rewind;
use function str_ends_with;
use function str_starts_with;
use function strlen;

/**
 * Finds the messages in an mbox file, reading it line by line.
 *
 * @internal Used by Mbox and Folder\MboxTree.
 */
final class MboxScanner
{
    /**
     * Whether a file starts with a "From " line.
     */
    public static function isMboxFile(string $filename): bool
    {
        $fh = FileSystem::quietly(static fn(): mixed => fopen($filename, mode: 'rb'));
        if (false === $fh) {
            return false;
        }

        $line = (string) fgets($fh, Content::CHUNK + 1);
        fclose($fh);

        return str_starts_with($line, 'From ');
    }

    /**
     * The start and end of each message, after its "From " line, or null when the file does not start with one.
     *
     * A message ends before the line break that precedes the next "From " line.
     * Lines longer than Content::CHUNK are read in pieces, and only the piece
     * that starts a line can be a "From " line.
     *
     * @param resource $fh
     * @return list<array{int, int}>|null
     *
     * @mago-expect analysis:missing-parameter-type Streams have no native parameter type.
     */
    public static function findMessages($fh): ?array
    {
        rewind($fh);
        $size      = (int) (fstat($fh)['size'] ?? 0);
        $positions = [];
        $start     = -1;
        $offset    = 0;
        $lineStart = true;
        $previous  = '';
        while ($offset < $size) {
            $piece = (string) fgets($fh, Content::CHUNK + 1);
            if ($lineStart && str_starts_with($piece, 'From ')) {
                if ($start >= 0) {
                    $positions[] = [$start, $offset - self::lineBreakLength($previous)];
                }

                $start = $offset + strlen($piece);
            }

            if ($start < 0) {
                return null;
            }

            $lineStart = str_ends_with($piece, "\n");
            $previous  = $piece;
            $offset    += max(1, strlen($piece));
        }

        if ($start < 0) {
            return null;
        }

        $positions[] = [$start, $size];

        return $positions;
    }

    /**
     * The length of the line break ending the line before a "From " line, which always has one.
     */
    private static function lineBreakLength(string $previous): int
    {
        return str_ends_with($previous, "\r\n") ? 2 : 1;
    }
}
