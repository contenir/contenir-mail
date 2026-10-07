<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Trait;

use PharData;

use function file_exists;
use function is_dir;
use function mkdir;

/**
 * Unpacks the shared maildir.tar fixture on first use.
 *
 * PharData skips empty directories when extracting, so the empty
 * `.subfolder/cur` directory the fixture depends on is recreated here.
 * Without it, whichever test class extracts the archive first decides
 * whether every Maildir test sees a valid fixture.
 */
trait ExtractsMaildirFixtureTrait
{
    protected function extractMaildirFixture(string $originalMaildir): void
    {
        if (! file_exists("{$originalMaildir}maildirsize")) {
            (new PharData("{$originalMaildir}maildir.tar"))->extractTo($originalMaildir);
        }

        if (! is_dir("{$originalMaildir}.subfolder/cur")) {
            mkdir("{$originalMaildir}.subfolder/cur", permissions: 0o777, recursive: true);
        }
    }
}
