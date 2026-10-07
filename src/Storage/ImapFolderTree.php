<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use function count;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function ksort;

use const SORT_STRING;

/**
 * Builds a folder tree from an IMAP LIST response.
 *
 * @internal Used by Imap.
 *
 * @mago-expect analysis:mixed-assignment The protocol returns the LIST response untyped; it is typed here.
 */
final class ImapFolderTree
{
    /**
     * @param array<array-key, mixed> $folders Global name to ["delim" => string, "flags" => list<string>].
     * @return array{Folder, string|null} The root, and the delimiter the server uses.
     */
    public static function build(array $folders): array
    {
        ksort($folders, SORT_STRING);
        $root      = new Folder('/', '/', selectable: false);
        $delimiter = null;
        foreach ($folders as $globalName => $data) {
            $data       = is_array($data) ? $data : [];
            $delim      = $data['delim'] ?? null;
            $delimiter  = is_string($delim) && '' !== $delim ? $delim : $delimiter;
            $flags      = $data['flags'] ?? [];
            $selectable = ! (is_array($flags) && in_array('\Noselect', $flags, strict: true));
            self::add($root, (string) $globalName, is_string($delim) ? $delim : '', $selectable);
        }

        return [$root, $delimiter];
    }

    /**
     * Add a folder by its global name, adding missing parents as folders that cannot be selected.
     */
    private static function add(Folder $root, string $globalName, string $delimiter, bool $selectable): void
    {
        $parts  = '' === $delimiter ? [$globalName] : explode($delimiter, $globalName);
        $last   = count($parts) - 1;
        $parent = $root;
        $path   = '';
        foreach ($parts as $index => $part) {
            $path .= (0 === $index ? '' : $delimiter) . $part;
            if (! $parent->hasFolder($part)) {
                $parent->addFolder(new Folder($part, $path, $index === $last && $selectable));
            }

            $parent = $parent->getFolder($part);
        }
    }
}
