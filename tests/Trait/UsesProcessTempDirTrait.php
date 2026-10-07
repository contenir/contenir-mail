<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Trait;

use function count;
use function getmypid;
use function is_dir;
use function register_shutdown_function;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;

/**
 * A scratch directory private to the running PHP process.
 *
 * Storage tests copy their fixtures into it, so suites running in parallel
 * processes (Infection's mutant runs, for one) never share files. It is
 * removed when the process exits, once the tests have emptied it.
 */
trait UsesProcessTempDirTrait
{
    private static bool $processTempDirCleanupRegistered = false;

    protected static function processTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/contenir-mail-tests-' . getmypid() . '/';

        if (! self::$processTempDirCleanupRegistered) {
            self::$processTempDirCleanupRegistered = true;
            register_shutdown_function(static function () use ($dir): void {
                $entries = is_dir($dir) ? scandir($dir) : false;
                if (false !== $entries && 2 === count($entries)) {
                    rmdir($dir);
                }
            });
        }

        return $dir;
    }
}
