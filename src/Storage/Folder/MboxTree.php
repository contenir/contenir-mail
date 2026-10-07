<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Folder;

use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\FileSystem;
use Contenir\Mail\Storage\Folder;
use Contenir\Mail\Storage\MboxScanner;

use function is_dir;
use function is_file;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;

/**
 * Reads the folder tree of a directory of mbox files.
 *
 * @internal Used by Folder\Mbox.
 */
final class MboxTree
{
    /**
     * The folders of a directory tree of mbox files: a file is a folder, a
     * directory a folder of folders. Hidden entries and symbolic links are
     * skipped, and directories deeper than $maxDepth are not read.
     *
     * @throws Exception\RuntimeException When the root directory cannot be read.
     */
    public static function folderTree(string $rootdir, int $maxDepth): Folder
    {
        $root = new Folder('/', '/', selectable: false);
        self::addFolders($root, $rootdir, '', $maxDepth);

        return $root;
    }

    /**
     * @throws Exception\RuntimeException When the directory cannot be read.
     */
    private static function addFolders(Folder $parent, string $directory, string $parentName, int $depthLeft): void
    {
        foreach (FileSystem::entries($directory) as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            $path       = $directory . $entry;
            $globalName = $parentName . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path)) {
                if (MboxScanner::isMboxFile($path)) {
                    $parent->addFolder(new Folder($entry, $globalName));
                }

                continue;
            }

            if (! is_dir($path) || $depthLeft < 1) {
                continue;
            }

            $folder = new Folder($entry, $globalName, selectable: false);
            $parent->addFolder($folder);
            self::addFolders($folder, $path . DIRECTORY_SEPARATOR, $globalName, $depthLeft - 1);
        }
    }
}
