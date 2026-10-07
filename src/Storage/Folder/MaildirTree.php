<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Folder;

use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\FileSystem;
use Contenir\Mail\Storage\Folder;
use Contenir\Mail\Storage\Maildir;

use function count;
use function explode;
use function implode;
use function in_array;
use function str_starts_with;
use function substr;

/**
 * Reads the folder tree of a Maildir++ directory: INBOX, and each ".Name" maildir in it.
 *
 * @internal Used by Folder\Maildir.
 */
final class MaildirTree
{
    /**
     * Folders are the maildirs whose names start with "." in the root;
     * symbolic links and anything else are skipped. A missing parent is
     * added as a folder that cannot be selected, and a name with an empty
     * part is skipped.
     *
     * @throws Exception\RuntimeException When the root cannot be read.
     */
    public static function read(string $rootdir, string $delim): Folder
    {
        $root = new Folder('/', '/', selectable: false);
        $root->addFolder(new Folder('INBOX'));
        foreach (FileSystem::entries($rootdir) as $entry) {
            $parts = explode($delim, substr($entry, offset: 1));
            if (
                ! str_starts_with($entry, '.')
                || in_array('', $parts, strict: true)
                || ! Maildir::isMaildir($rootdir . $entry)
            ) {
                continue;
            }

            self::add($root, $parts, $delim);
        }

        return $root;
    }

    /**
     * Entries are read in sorted order, so a parent is always added before its children.
     *
     * @param list<string> $parts
     */
    private static function add(Folder $root, array $parts, string $delim): void
    {
        $parent = $root;
        $path   = [];
        $last   = count($parts) - 1;
        foreach ($parts as $index => $part) {
            $path[] = $part;
            if (! $parent->hasFolder($part)) {
                $parent->addFolder(new Folder($part, implode($delim, $path), $index === $last));
            }

            $parent = $parent->getFolder($part);
        }
    }
}
