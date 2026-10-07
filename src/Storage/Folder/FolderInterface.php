<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Folder;

use Contenir\Mail\Storage\Exception\ExceptionInterface;
use Contenir\Mail\Storage\Folder;

/**
 * A storage with folders.
 *
 * @api
 */
interface FolderInterface
{
    /**
     * The folder tree from the root, or from the folder of this global name.
     *
     * @throws ExceptionInterface When there is no such folder.
     */
    public function getFolders(?string $rootFolder = null): Folder;

    /**
     * Select a folder by its global name; it must be selectable.
     *
     * @throws ExceptionInterface When there is no such folder, or it cannot be selected.
     */
    public function selectFolder(Folder|string $globalName): void;

    /**
     * The global name of the selected folder.
     */
    public function getCurrentFolder(): string;
}
