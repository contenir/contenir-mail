<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Folder;

use Contenir\Mail\Storage;
use Contenir\Mail\Storage\Exception;
use Override;

use function explode;
use function is_iterable;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * A Maildir++ tree: the maildir itself is INBOX, and each ".Name" maildir in it a folder.
 *
 * Folder names are split by the delimiter: ".Archive.2024" is "2024" inside
 * "Archive". Only maildirs found in the root are folders: hidden names,
 * symbolic links and anything else are skipped, so a folder name can never
 * reach outside the tree.
 *
 * @api
 */
class Maildir extends Storage\Maildir implements FolderInterface
{
    protected Storage\Folder $rootFolder;

    protected string $rootdir;

    protected string $delim;

    protected string $currentFolder = '';

    /**
     * @param MaildirConfig|iterable<mixed, mixed> $config A Folder\MaildirConfig, or its settings.
     * @throws Exception\ExceptionInterface When the settings are invalid, or the folder cannot be selected.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a setting is unknown or has the wrong type.
     */
    public function __construct(MaildirConfig|iterable $config)
    {
        $config                                      = is_iterable($config)
            ? MaildirConfig::fromIterable($config)
            : $config;
        $this->rootdir                               = rtrim($config->dirname, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR;
        $this->delim                                 = $config->delim;
        $this->has[Storage\Capability::Top->value]   = true;
        $this->has[Storage\Capability::Flags->value] = true;
        $this->rootFolder                            = $this->buildFolderTree();
        $this->selectFolder($config->folder);
    }

    /**
     * @throws Exception\InvalidArgumentException When there is no such folder.
     */
    #[Override]
    public function getFolders(?string $rootFolder = null): Storage\Folder
    {
        $name = $this->localPath((string) $rootFolder);
        if ('' === $name) {
            return $this->rootFolder;
        }

        $folder = $this->rootFolder;
        foreach (explode($this->delim, $name) as $part) {
            if (! $folder->hasFolder($part)) {
                throw new Exception\InvalidArgumentException("Folder {$rootFolder} not found");
            }

            $folder = $folder->getFolder($part);
        }

        return $folder;
    }

    /**
     * @throws Exception\InvalidArgumentException When there is no such folder.
     * @throws Exception\RuntimeException When the folder is not selectable or has gone.
     */
    #[Override]
    public function selectFolder(Storage\Folder|string $globalName): void
    {
        $name   = $this->localPath((string) $globalName);
        $folder = '' === $name ? $this->rootFolder->getFolder('INBOX') : $this->getFolders($name);
        if (! $folder->isSelectable()) {
            throw new Exception\RuntimeException("{$globalName} is not selectable");
        }

        try {
            $this->openMaildir($this->folderPath($name));
        } catch (Exception\ExceptionInterface $e) {
            $this->rootFolder = $this->buildFolderTree();

            throw new Exception\RuntimeException(
                'The maildir has gone; the folder tree has been read again, so look for the folder again',
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
     * The folder name relative to the root: "" for INBOX, and without a leading "INBOX" and delimiter.
     */
    protected function localPath(string $globalName): string
    {
        $name = trim($globalName, $this->delim);
        if ('INBOX' === $name || '/' === $name) {
            return '';
        }

        return str_starts_with($name, "INBOX{$this->delim}") ? substr($name, strlen("INBOX{$this->delim}")) : $name;
    }

    /**
     * The directory of a folder, from its name relative to the root.
     */
    protected function folderPath(string $localPath): string
    {
        return '' === $localPath ? rtrim($this->rootdir, DIRECTORY_SEPARATOR) : "{$this->rootdir}.{$localPath}";
    }

    /**
     * @throws Exception\RuntimeException When the root cannot be read.
     */
    protected function buildFolderTree(): Storage\Folder
    {
        return MaildirTree::read($this->rootdir, $this->delim);
    }
}
