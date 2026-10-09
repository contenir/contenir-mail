<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use function basename;
use function dirname;
use function in_array;
use function is_dir;
use function preg_match;
use function str_split;
use function strpos;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * What a maildir file name says: "unique,S=size:2,flags".
 *
 * The unique ID, the size in bytes when a Maildir++ writer added it, and
 * the flag letters; letters without a Flag case are kept as keywords.
 *
 * @internal Used by MaildirFiles and Writable\Maildir.
 */
final class MaildirFilename
{
    /**
     * The unique ID, size and flags in a file name.
     *
     * @param list<Flag|string> $defaultFlags
     * @return array{uniq: string, flags: list<Flag|string>, size: int|null}
     */
    public static function parse(string $entry, array $defaultFlags): array
    {
        $colon   = strpos($entry, needle: ':');
        $uniq    = false === $colon ? $entry : substr($entry, offset: 0, length: $colon);
        $info    = false === $colon ? '' : substr($entry, $colon + 1);
        $matches = [];
        $size    = 1 === preg_match('/,S=(\d{1,18})(?:,|$)/D', $uniq, $matches) ? (int) ($matches[1] ?? 0) : null;
        $flags   = $defaultFlags;
        $letters = 1 === preg_match('/^2,(.+)$/D', $info, $matches) ? str_split($matches[1] ?? '') : [];
        foreach ($letters as $letter) {
            $flag = Flag::fromMaildir($letter);
            if (! in_array($flag, $flags, strict: true)) {
                $flags[] = $flag;
            }
        }

        return ['uniq' => $uniq, 'flags' => $flags, 'size' => $size];
    }

    /**
     * Where a message file is now, after its flags changed and Maildir renamed it, or moved
     * it from new to cur: the file in either with the same unique name, or null.
     *
     * @throws Exception\RuntimeException When the directory cannot be read.
     */
    public static function moved(string $filename): ?string
    {
        $maildir = dirname($filename, levels: 2);
        $uniq    = self::parse(basename($filename), [])['uniq'];
        foreach (['cur', 'new'] as $folder) {
            $directory = $maildir . DIRECTORY_SEPARATOR . $folder;
            if (! is_dir($directory)) {
                continue;
            }

            foreach (FileSystem::entries($directory) as $entry) {
                if (self::parse($entry, [])['uniq'] === $uniq) {
                    return $directory . DIRECTORY_SEPARATOR . $entry;
                }
            }
        }

        return null;
    }
}
