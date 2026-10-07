<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use function in_array;
use function preg_match;
use function str_split;
use function strpos;
use function substr;

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
}
