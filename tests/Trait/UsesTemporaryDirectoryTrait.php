<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Trait;

use function bin2hex;
use function chmod;
use function is_dir;
use function is_link;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * A directory private to one test, removed again, symbolic links and all, without following any link.
 *
 * Call setUpTemporaryDirectory() from setUp() and tearDownTemporaryDirectory() from tearDown().
 */
trait UsesTemporaryDirectoryTrait
{
    private string $temporaryDirectory = '';

    protected function setUpTemporaryDirectory(): string
    {
        $this->temporaryDirectory =
            sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'contenir-mail-test-'
            . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, permissions: 0o700);

        return $this->temporaryDirectory;
    }

    protected function tearDownTemporaryDirectory(): void
    {
        if ('' !== $this->temporaryDirectory && is_dir($this->temporaryDirectory)) {
            self::removeTree($this->temporaryDirectory);
        }

        $this->temporaryDirectory = '';
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || ! is_dir($path)) {
            unlink($path);

            return;
        }

        chmod($path, permissions: 0o700);
        $entries = scandir($path);
        foreach (false === $entries ? [] : $entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            self::removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }

        rmdir($path);
    }
}
