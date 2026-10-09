<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\Storage\Part\Content;

use function array_values;
use function filesize;
use function in_array;
use function is_dir;
use function is_file;
use function is_link;
use function ksort;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const SORT_STRING;

/**
 * The message files of a maildir, and what their names say.
 *
 * Hidden entries, symbolic links and anything but regular files are not
 * messages; see MaildirFilename for what a name holds.
 *
 * @internal Used by Maildir and Writable\Maildir.
 */
final class MaildirFiles
{
    /**
     * The messages of a maildir's cur/ and, when present, new/, in file name order.
     * A cur/ or new/ that is a symbolic link is not read.
     *
     * @return list<array{uniq: string, flags: list<Flag|string>, filename: string, size: int|null}>
     * @throws Exception\RuntimeException When cur/ or new/ cannot be read.
     */
    public static function read(string $dirname): array
    {
        $byName = [];
        foreach (['cur' => [], 'new' => [Flag::Recent]] as $subdir => $flags) {
            $directory = $dirname . DIRECTORY_SEPARATOR . $subdir;
            if (is_link($directory) || ! is_dir($directory)) {
                continue;
            }

            foreach (self::directory($directory, $flags) as $file) {
                $byName[$file['filename']] = $file;
            }
        }

        ksort($byName, SORT_STRING);

        return array_values($byName);
    }

    /**
     * @param list<Flag|string> $have
     * @param array<array-key, Flag|string> $wanted
     */
    public static function hasFlags(array $have, array $wanted): bool
    {
        foreach ($wanted as $flag) {
            if (! in_array(Flag::normalise($flag), $have, strict: true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The size from the file name, or else from the file.
     */
    public static function size(string $filename, ?int $size): int
    {
        return $size ?? (int) FileSystem::quietly(static fn(): int|false => filesize($filename));
    }

    /**
     * A message file, opened only while its bytes are read, so a held message holds no open file.
     */
    public static function content(string $filename): Content
    {
        return Content::fromFile($filename, 0, self::size($filename, null), MaildirFilename::moved(...));
    }

    /**
     * @param list<Flag|string> $defaultFlags
     * @return list<array{uniq: string, flags: list<Flag|string>, filename: string, size: int|null}>
     * @throws Exception\RuntimeException When the directory cannot be read.
     */
    private static function directory(string $directory, array $defaultFlags): array
    {
        $files = [];
        foreach (FileSystem::entries($directory) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (str_starts_with($entry, '.') || ! is_file($path)) {
                continue;
            }

            $parsed  = MaildirFilename::parse($entry, $defaultFlags);
            $files[] = [
                'uniq'     => $parsed['uniq'],
                'flags'    => $parsed['flags'],
                'filename' => $path,
                'size'     => $parsed['size'],
            ];
        }

        return $files;
    }
}
