<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Folder;

use Contenir\Mail\Storage;
use Contenir\Mail\Storage\Exception;
use Override;

use function explode;
use function is_dir;
use function is_iterable;
use function ltrim;
use function rtrim;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * A directory tree of mbox files: each file is a folder, each directory a folder of folders.
 *
 * Folders are found by reading the directory tree once. Hidden entries and
 * symbolic links are skipped, so a folder name can only reach files inside
 * the tree, and the tree is read at most MAX_DEPTH directories deep.
 *
 * @api
 */
final class Mbox extends Storage\Mbox implements FolderInterface
{
    /** Deepest directory read when building the folder tree */
    public const int MAX_DEPTH = 32;

    /**
     * The headers can be read alone, and messages have no unique IDs.
     *
     * @var array<string, bool|null>
     */
    protected array $has = [
        Storage\Capability::UniqueId->value  => false,
        Storage\Capability::Delete->value    => false,
        Storage\Capability::Create->value    => false,
        Storage\Capability::Top->value       => true,
        Storage\Capability::FetchPart->value => true,
        Storage\Capability::Flags->value     => false,
    ];

    private Storage\Folder $rootFolder;

    private string $rootdir;

    private string $currentFolder = '';

    /**
     * @param MboxConfig|iterable<mixed, mixed> $config A Folder\MboxConfig, or its settings.
     * @throws Exception\ExceptionInterface When the settings are invalid, or the folder cannot be selected.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a setting is unknown or has the wrong type.
     */
    public function __construct(MboxConfig|iterable $config)
    {
        $config = is_iterable($config) ? MboxConfig::fromIterable($config) : $config;
        if (! is_dir($config->dirname)) {
            throw new Exception\InvalidArgumentException("{$config->dirname} is not a directory");
        }

        $this->format  = $config->format;
        $this->rootdir = rtrim($config->dirname, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR;
        $this->rootFolder = $this->buildFolderTree();
        $this->selectFolder($config->folder);
    }

    /**
     * @throws Exception\InvalidArgumentException When there is no such folder.
     */
    #[Override]
    public function getFolders(?string $rootFolder = null): Storage\Folder
    {
        $folder = $this->rootFolder;
        $path   = trim((string) $rootFolder, DIRECTORY_SEPARATOR);
        if ('' === $path) {
            return $folder;
        }

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $name) {
            if (! $folder->hasFolder($name)) {
                throw new Exception\InvalidArgumentException("Folder {$rootFolder} not found");
            }

            $folder = $folder->getFolder($name);
        }

        return $folder;
    }

    /**
     * @throws Exception\InvalidArgumentException When there is no such folder.
     * @throws Exception\RuntimeException When the folder is not selectable or its file has gone.
     */
    #[Override]
    public function selectFolder(Storage\Folder|string $globalName): void
    {
        $folder = $this->getFolders((string) $globalName);
        if (! $folder->isSelectable()) {
            throw new Exception\RuntimeException("{$globalName} is not selectable");
        }

        try {
            $this->openMboxFile($this->rootdir . ltrim($folder->getGlobalName(), DIRECTORY_SEPARATOR));
        } catch (Exception\ExceptionInterface $e) {
            $this->rootFolder = $this->buildFolderTree();

            throw new Exception\RuntimeException(
                'The mbox file has gone; the folder tree has been read again, so look for the folder again',
                0,
                $e,
            );
        }

        $this->currentFolder = $folder->getGlobalName();
    }

    #[Override]
    public function getCurrentFolder(): string
    {
        return $this->currentFolder;
    }

    /**
     * @throws Exception\RuntimeException When the root directory cannot be read.
     */
    private function buildFolderTree(): Storage\Folder
    {
        return MboxTree::folderTree($this->rootdir, self::MAX_DEPTH);
    }
}
