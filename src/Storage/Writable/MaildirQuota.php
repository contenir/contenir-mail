<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Writable;

use Contenir\Mail\Storage\FileSystem;
use Contenir\Mail\Storage\MaildirFilename;
use Contenir\Mail\Storage\MaildirFiles;

use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function implode;
use function is_dir;
use function is_file;
use function is_link;
use function max;
use function min;
use function preg_match;
use function rename;
use function str_starts_with;
use function strlen;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const FILE_APPEND;
use const LOCK_EX;

/**
 * The Maildir++ "maildirsize" file, which anyone who can deliver to the
 * maildir can write, so every field is checked and it is never followed
 * through a symbolic link.
 *
 * The first line defines the quota, such as "1000000S,1000C" for a million
 * bytes and a thousand messages; each later line adds "bytes messages",
 * negative for removals. Unknown quota fields and malformed lines are
 * ignored, and totals are kept within the range of an integer.
 *
 * @mago-expect lint:cyclomatic-complexity Each step reads, checks or writes one part of maildirsize.
 * @mago-expect lint:kan-defect Each step reads, checks or writes one part of maildirsize.
 *
 * @internal Used by Writable\Maildir.
 */
final class MaildirQuota
{
    /** Bytes of maildirsize read; a longer file is recalculated, as Maildir++ asks */
    public const int MAX_FILE_BYTES = 5120;

    /** Largest value taken from one field; larger ones are clamped */
    private const int MAX_VALUE = 1_000_000_000_000_000;

    /**
     * @return array{size?: int, count?: int}
     */
    public static function parseDefinition(string $line): array
    {
        $quota = [];
        foreach (explode(',', $line) as $member) {
            $matches = [];
            if (1 !== preg_match('/^(?<value>\d{1,18})(?<kind>[SC])\z/', trim($member), $matches)) {
                continue;
            }

            $quota['C' === ($matches['kind'] ?? '') ? 'count' : 'size'] = self::clamp((int) ($matches['value'] ?? ''));
        }

        return $quota;
    }

    /**
     * The total bytes and messages of the "bytes messages" lines.
     *
     * @param iterable<string> $lines
     * @return array{int, int}
     */
    public static function sumEntries(iterable $lines): array
    {
        $size  = 0;
        $count = 0;
        foreach ($lines as $line) {
            $matches = [];
            if (1 !== preg_match('/^\s*(?<size>-?\d{1,18})\s+(?<count>-?\d{1,18})\s*\z/', $line, $matches)) {
                continue;
            }

            $size  = self::clamp($size + self::clamp((int) ($matches['size'] ?? '')));
            $count = self::clamp($count + self::clamp((int) ($matches['count'] ?? '')));
        }

        return [$size, $count];
    }

    /**
     * The definition line for a quota.
     *
     * @param array{size?: int, count?: int} $quota
     */
    public static function definition(array $quota): string
    {
        $members = [];
        if (null !== ($quota['size'] ?? null)) {
            $members[] = "{$quota['size']}S";
        }

        if (null !== ($quota['count'] ?? null)) {
            $members[] = "{$quota['count']}C";
        }

        return implode(',', $members);
    }

    /**
     * maildirsize, or null when it is missing, a symbolic link, or longer than MAX_FILE_BYTES.
     */
    public static function read(string $rootdir): ?string
    {
        $path = "{$rootdir}maildirsize";
        if (is_link($path) || ! is_file($path)) {
            return null;
        }

        $contents = FileSystem::quietly(static fn(): string|false => file_get_contents(
            $path,
            length: self::MAX_FILE_BYTES + 1,
        ));

        return false === $contents || strlen($contents) > self::MAX_FILE_BYTES ? null : $contents;
    }

    /**
     * The usage maildirsize records, against the quota given or else the one it defines.
     *
     * @param array{size?: int, count?: int}|null $quota
     * @return array{size: int, count: int, quota: array{size?: int, count?: int}}
     */
    public static function usage(string $contents, ?array $quota): array
    {
        $lines = explode("\n", $contents);
        [$size, $count] = self::sumEntries(array_slice($lines, offset: 1));

        return ['size' => $size, 'count' => $count, 'quota' => $quota ?? self::parseDefinition($lines[0])];
    }

    /**
     * @param array{size: int, count: int, quota: array{size?: int, count?: int}} $usage
     */
    public static function isOver(array $usage): bool
    {
        return (
            $usage['size'] > ($usage['quota']['size'] ?? $usage['size'])
                || $usage['count'] > ($usage['quota']['count'] ?? $usage['count'])
        );
    }

    /**
     * Append a "bytes messages" line, when maildirsize exists and is not a symbolic link.
     */
    public static function append(string $rootdir, int $size, int $count): void
    {
        $path = "{$rootdir}maildirsize";
        if (is_link($path) || ! is_file($path)) {
            return;
        }

        FileSystem::quietly(static fn(): int|false => file_put_contents(
            $path,
            "{$size} {$count}\n",
            FILE_APPEND | LOCK_EX,
        ));
    }

    /**
     * The total size and number of message files in some directories, and each directory's modification time.
     *
     * @param list<string> $directories
     * @return array{size: int, count: int, timestamps: array<string, int|false>}
     * @throws \Contenir\Mail\Storage\Exception\RuntimeException When a directory cannot be read.
     */
    public static function count(array $directories): array
    {
        $size       = 0;
        $count      = 0;
        $timestamps = [];
        foreach ($directories as $directory) {
            if (is_link($directory) || ! is_dir($directory)) {
                continue;
            }

            $timestamps[$directory] = FileSystem::quietly(static fn(): int|false => filemtime($directory));
            foreach (FileSystem::entries($directory) as $entry) {
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (str_starts_with($entry, '.') || ! is_file($path)) {
                    continue;
                }

                $size = self::clamp($size + MaildirFiles::size($path, MaildirFilename::parse($entry, [])['size']));
                ++$count;
            }
        }

        return ['size' => $size, 'count' => $count, 'timestamps' => $timestamps];
    }

    /**
     * Move a newly written maildirsize into place, and remove it again when
     * a directory changed while it was being counted, as Maildir++ asks.
     *
     * @param array<string, int|false> $timestamps
     */
    public static function replace(string $rootdir, string $written, array $timestamps): void
    {
        $path = "{$rootdir}maildirsize";
        FileSystem::quietly(static fn(): bool => rename($written, $path));
        $now = array_map(
            static fn(string $directory): int|false => FileSystem::quietly(
                static fn(): int|false => filemtime($directory),
            ),
            array_keys($timestamps),
        );
        if (array_values($timestamps) !== $now) {
            FileSystem::quietly(static fn(): bool => unlink($path));
        }
    }

    private static function clamp(int $value): int
    {
        return max(-self::MAX_VALUE, min($value, self::MAX_VALUE));
    }
}
