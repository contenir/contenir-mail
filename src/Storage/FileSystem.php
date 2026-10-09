<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Closure;

use function closedir;
use function fopen;
use function is_dir;
use function is_link;
use function mkdir;
use function opendir;
use function readdir;
use function restore_error_handler;
use function set_error_handler;
use function sort;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * File system helpers: calls whose failure is reported by their return
 * value, without the PHP warning they also raise, and directory listings
 * that never follow a symbolic link.
 *
 * @mago-expect lint:cyclomatic-complexity Each small helper checks the result of a file system call and refuses symbolic links.
 *
 * @internal
 */
final class FileSystem
{
    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public static function quietly(Closure $operation): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The entries of a directory, sorted, without "." and ".." or symbolic links.
     *
     * @return list<string>
     * @throws Exception\RuntimeException When the directory cannot be read.
     */
    public static function entries(string $directory): array
    {
        $handle = self::quietly(static fn(): mixed => opendir($directory));
        if (false === $handle) {
            throw new Exception\RuntimeException("Cannot read directory {$directory}");
        }

        $entries = [];
        while (false !== ($entry = readdir($handle))) {
            if ('.' === $entry || '..' === $entry || is_link($directory . DIRECTORY_SEPARATOR . $entry)) {
                continue;
            }

            $entries[] = $entry;
        }

        closedir($handle);
        sort($entries);

        return $entries;
    }

    /**
     * Create a directory where missing, refusing a symbolic link in its place.
     *
     * @throws Exception\RuntimeException When the directory cannot be created, or a symbolic link is in its place.
     */
    public static function createDirectory(string $path, int $mode): void
    {
        if (is_link($path)) {
            throw new Exception\RuntimeException("{$path} may not be a symbolic link");
        }

        if (! is_dir($path) && ! self::quietly(static fn(): bool => mkdir($path, $mode)) && ! is_dir($path)) {
            throw new Exception\RuntimeException("Cannot create {$path}");
        }
    }

    /**
     * Remove the files in a directory; symbolic links are removed, not followed. A missing directory is left.
     *
     * @throws Exception\RuntimeException When the directory is a symbolic link, or a file cannot be removed.
     */
    public static function emptyDirectory(string $directory): void
    {
        if (is_link($directory)) {
            throw new Exception\RuntimeException("{$directory} may not be a symbolic link");
        }

        if (! is_dir($directory)) {
            return;
        }

        foreach (self::files($directory) as $path) {
            if (! self::quietly(static fn(): bool => unlink($path))) {
                throw new Exception\RuntimeException("Cannot remove {$path}");
            }
        }
    }

    /**
     * Every entry of a directory but "." and "..", and subdirectories that are not links.
     *
     * @return list<string>
     */
    private static function files(string $directory): array
    {
        $handle = self::quietly(static fn(): mixed => opendir($directory));
        if (false === $handle) {
            return [];
        }

        $files = [];
        while (false !== ($entry = readdir($handle))) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if ('.' === $entry || '..' === $entry || (is_dir($path) && ! is_link($path))) {
                continue;
            }

            $files[] = $path;
        }

        closedir($handle);

        return $files;
    }

    /**
     * A file opened for reading, or, when it has gone, the file $relocate finds in its place.
     *
     * @param (Closure(string): ?string)|null $relocate
     * @return array{resource|false, string} The stream, and the path it was opened from.
     */
    public static function openMoved(string $path, ?Closure $relocate): array
    {
        $stream = self::quietly(static fn(): mixed => fopen($path, mode: 'rb'));
        $moved  = false === $stream && null !== $relocate ? $relocate($path) : null;
        if (null === $moved) {
            return [$stream, $path];
        }

        return [self::quietly(static fn(): mixed => fopen($moved, mode: 'rb')), $moved];
    }
}
