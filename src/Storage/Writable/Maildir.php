<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Writable;

use Contenir\Mail\Message as ComposedMessage;
use Contenir\Mail\Mime\Exception\RuntimeException as MimeException;
use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\FileSystem;
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\Folder;
use Contenir\Mail\Storage\LocalPath;
use Contenir\Mail\Storage\MaildirFiles;
use Contenir\Mail\Storage\Message;
use Override;
use RecursiveIteratorIterator;

use function array_filter;
use function array_values;
use function dirname;
use function explode;
use function file_exists;
use function filesize;
use function fopen;
use function in_array;
use function is_array;
use function is_dir;
use function is_iterable;
use function is_link;
use function iterator_to_array;
use function link;
use function preg_match;
use function rename;
use function rmdir;
use function str_starts_with;
use function strlen;
use function substr;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * A writable Maildir++ tree: store, copy, move and flag messages, and manage folders and quota.
 *
 * Messages are written as Maildir delivery asks: to a new file in tmp/,
 * opened exclusively, synced to disk, then linked into cur/ or new/, so a
 * reader never sees half a message. Files and directories are created
 * private to the owner by default; see MaildirConfig.
 *
 * Folder names may not hold "/", "\", control characters, "." or ".."
 * parts, or empty parts, and nothing is written through a symbolic link:
 * a folder, tmp/, cur/ or new/ that is a link is refused.
 *
 * @mago-expect lint:too-many-methods The WritableInterface operations, quota handling and their checks.
 * @mago-expect lint:cyclomatic-complexity Each write checks its folder, flags and the result of each file system step.
 * @mago-expect lint:kan-defect Each write checks its folder, flags and the result of each file system step.
 *
 * @api
 */
final class Maildir extends Folder\Maildir implements WritableInterface
{
    private int $directoryMode = 0o700;

    private int $fileMode = 0o600;

    /** @var bool|array{size?: int, count?: int} */
    private bool|array $quota = false;

    /**
     * @param MaildirConfig|iterable<mixed, mixed> $config A Writable\MaildirConfig, or its settings.
     * @throws Exception\ExceptionInterface When the settings are invalid, the maildir cannot be created, or the folder cannot be selected.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a setting is unknown or has the wrong type.
     */
    public function __construct(MaildirConfig|iterable $config)
    {
        $config              = is_iterable($config) ? MaildirConfig::fromIterable($config) : $config;
        $this->directoryMode = $config->directoryMode;
        $this->fileMode      = $config->fileMode;
        if ($config->create && ! file_exists($config->dirname . DIRECTORY_SEPARATOR . 'cur')) {
            self::initMaildir($config->dirname, $config->directoryMode);
        }

        parent::__construct($config->folderConfig());
        $this->has['create'] = true;
        $this->has['delete'] = true;
    }

    /**
     * Create a maildir: the directory, where missing, and its cur/, new/ and tmp/.
     *
     * An existing maildir is left as it is.
     *
     * @throws Exception\InvalidArgumentException When the path is not local, is a symbolic link or a file, or its parent is missing.
     * @throws Exception\RuntimeException When a directory cannot be created.
     */
    public static function initMaildir(string $dir, int $directoryMode = 0o700): void
    {
        LocalPath::check($dir, 'dirname');
        if (is_link($dir) || (file_exists($dir) && ! is_dir($dir))) {
            throw new Exception\InvalidArgumentException(
                'The maildir must be a directory, not a file or a symbolic link',
            );
        }

        if (! is_dir(dirname($dir))) {
            throw new Exception\InvalidArgumentException('The parent of the maildir does not exist');
        }

        FileSystem::createDirectory($dir, $directoryMode);
        foreach (['cur', 'new', 'tmp'] as $subdir) {
            FileSystem::createDirectory($dir . DIRECTORY_SEPARATOR . $subdir, $directoryMode);
        }
    }

    /**
     * @throws Exception\ExceptionInterface When the folder exists, or the name is not allowed.
     */
    #[Override]
    public function createFolder(string $name, Folder|string|null $parentFolder = null): void
    {
        $global = null === $parentFolder ? $name : "{$parentFolder}{$this->delim}{$name}";
        $local  = $this->checkFolderName($global);
        if ($this->folderExists($local)) {
            throw new Exception\RuntimeException("Folder {$global} already exists");
        }

        self::initMaildir($this->folderPath($local), $this->directoryMode);
        $this->rootFolder = $this->buildFolderTree();
    }

    /**
     * @throws Exception\ExceptionInterface When the folder is INBOX, selected, has subfolders or cannot be removed.
     */
    #[Override]
    public function removeFolder(Folder|string $name): void
    {
        $local = $this->localPath((string) $name);
        if (! $this->writableFolder($local, 'remove')->isLeaf()) {
            throw new Exception\RuntimeException('Remove the subfolders first');
        }

        $dir = $this->folderPath($local);
        foreach (["{$dir}/tmp", "{$dir}/new", "{$dir}/cur", $dir] as $path) {
            FileSystem::emptyDirectory($path);
            if (is_dir($path) && ! FileSystem::quietly(static fn(): bool => rmdir($path))) {
                throw new Exception\RuntimeException("Cannot remove {$path}");
            }
        }

        $this->rootFolder = $this->buildFolderTree();
    }

    /**
     * Rename a folder and its subfolders.
     *
     * @throws Exception\ExceptionInterface When the folder is INBOX or selected, or the new name is taken or not allowed.
     */
    #[Override]
    public function renameFolder(Folder|string $oldName, string $newName): void
    {
        $old    = $this->localPath((string) $oldName);
        $new    = $this->checkFolderName($newName);
        $folder = $this->writableFolder($old, 'rename');
        if ($new === $old || str_starts_with($new, $old . $this->delim)) {
            throw new Exception\RuntimeException('The new folder cannot be the old folder or one of its children');
        }

        if ($this->folderExists($new)) {
            throw new Exception\RuntimeException("Folder {$newName} already exists");
        }

        $tree = new RecursiveIteratorIterator($folder, RecursiveIteratorIterator::SELF_FIRST);
        foreach ([$folder, ...iterator_to_array($tree, preserve_keys: false)] as $source) {
            $from = $this->folderPath($source->getGlobalName());
            $to   = $this->folderPath($new . substr($source->getGlobalName(), strlen($old)));
            if (! $source->isSelectable()) {
                continue;
            }

            if (file_exists($to) || is_link($from) || ! FileSystem::quietly(static fn(): bool => rename($from, $to))) {
                $this->rootFolder = $this->buildFolderTree();

                throw new Exception\RuntimeException("Cannot move {$source->getGlobalName()}");
            }
        }

        $this->rootFolder = $this->buildFolderTree();
    }

    /**
     * Store a message.
     *
     * @param string|resource|Message|ComposedMessage $message
     * @param iterable<Flag|string>|null $flags Seen when null.
     * @param bool $recent Deliver to new/ as a recent message, without flags, as a delivery agent would.
     * @throws Exception\ExceptionInterface When the storage is over quota, a flag cannot be stored, or writing fails.
     * @throws MimeException When a composed message cannot be written.
     *
     * @mago-expect lint:no-boolean-flag-parameter The laminas-mail signature; $recent picks new/ over cur/.
     */
    #[Override]
    public function appendMessage(
        mixed $message,
        Folder|string|null $folder = null,
        ?iterable $flags = null,
        bool $recent = false,
    ): void {
        $this->refuseOverQuota();
        $local = $this->selectableFolder($folder ?? $this->currentFolder);
        [$info, $named] = MaildirName::info($flags ?? [Flag::Seen]);
        $temporary = $this->targetDirectory($local, 'tmp');
        [$path, $uniq] = MaildirDelivery::writeTemporary($temporary, $message, $this->fileMode);
        $size   = (int) FileSystem::quietly(static fn(): int|false => filesize($path));
        $name   = $recent ? "{$uniq},S={$size}" : "{$uniq},S={$size}:{$info}";
        $target = $this->targetDirectory($local, $recent ? 'new' : 'cur') . DIRECTORY_SEPARATOR . $name;
        MaildirDelivery::deliver($path, $target);
        $this->track($local, $uniq, $recent ? [Flag::Recent] : $named, $target, $size);
    }

    /**
     * Copy a message to a folder, without its Recent flag.
     *
     * @throws Exception\ExceptionInterface When there is no such message or folder, or writing fails.
     * @throws MimeException Never: the copy is written from a file.
     */
    #[Override]
    public function copyMessage(int $id, Folder|string $folder): void
    {
        $this->refuseOverQuota();
        $file  = $this->file($id);
        $local = $this->selectableFolder($folder);
        [$info, $named] = MaildirName::info(self::withoutRecent($file['flags']));
        $source = FileSystem::quietly(static fn(): mixed => fopen($file['filename'], mode: 'rb'));
        if (false === $source) {
            throw new Exception\RuntimeException('Cannot read the message file; it may have been moved');
        }

        $temporary = $this->targetDirectory($local, 'tmp');
        [$path, $uniq] = MaildirDelivery::writeTemporary($temporary, $source, $this->fileMode);
        $size   = (int) FileSystem::quietly(static fn(): int|false => filesize($path));
        $target = $this->targetDirectory($local, 'cur') . DIRECTORY_SEPARATOR . "{$uniq},S={$size}:{$info}";
        MaildirDelivery::deliver($path, $target);
        $this->track($local, $uniq, $named, $target, $size);
    }

    /**
     * Move a message to another folder, without its Recent flag.
     *
     * @throws Exception\ExceptionInterface When there is no such message or folder, it is the current folder, or moving fails.
     */
    #[Override]
    public function moveMessage(int $id, Folder|string $folder): void
    {
        $file  = $this->file($id);
        $local = $this->selectableFolder($folder);
        if ($this->localPath($this->currentFolder) === $local) {
            throw new Exception\RuntimeException('The target is the current folder');
        }

        [$info] = MaildirName::info(self::withoutRecent($file['flags']));
        $size   = MaildirFiles::size($file['filename'], $file['size']);
        $name   = MaildirName::unique() . ",S={$size}:{$info}";
        $target = $this->targetDirectory($local, 'cur') . DIRECTORY_SEPARATOR . $name;
        $source = $file['filename'];
        if (! FileSystem::quietly(static fn(): bool => link($source, $target))) {
            throw new Exception\RuntimeException('Cannot move the message file');
        }

        FileSystem::quietly(static fn(): bool => unlink($source));
        unset($this->files[$id - 1]);
        $this->files = array_values($this->files);
    }

    /**
     * Replace a message's flags, moving it from new/ to cur/. Recent cannot be set.
     *
     * @param iterable<Flag|string> $flags
     * @throws Exception\ExceptionInterface When there is no such message, a flag cannot be stored, or renaming fails.
     */
    #[Override]
    public function setFlags(int $id, iterable $flags): void
    {
        $file = $this->file($id);
        [$info, $named] = MaildirName::info($flags);
        $directory = dirname($file['filename'], levels: 2) . DIRECTORY_SEPARATOR . 'cur';
        $target    = $directory . DIRECTORY_SEPARATOR . "{$file['uniq']}:{$info}";
        $source    = $file['filename'];
        if (is_link($directory) || ! FileSystem::quietly(static fn(): bool => rename($source, $target))) {
            throw new Exception\RuntimeException('Cannot rename the message file');
        }

        $this->files[$id - 1] = [
            'uniq'     => $file['uniq'],
            'flags'    => $named,
            'filename' => $target,
            'size'     => $file['size'],
        ];
    }

    /**
     * @throws Exception\ExceptionInterface When there is no such message or it cannot be removed.
     */
    #[Override]
    public function removeMessage(int $id): void
    {
        $file = $this->file($id);
        $size = MaildirFiles::size($file['filename'], $file['size']);
        $path = $file['filename'];
        if (! FileSystem::quietly(static fn(): bool => unlink($path))) {
            throw new Exception\RuntimeException('Cannot remove the message');
        }

        unset($this->files[$id - 1]);
        $this->files = array_values($this->files);
        $this->addQuotaEntry(-$size, -1);
    }

    /**
     * Turn quota checks on (true) or off (false), or set the quota to check against instead of maildirsize's.
     *
     * With checks on, storing is refused while over quota, and maildirsize
     * is updated as messages are stored and removed.
     *
     * @param bool|array{size?: int, count?: int} $value
     */
    public function setQuota(bool|array $value): void
    {
        $this->quota = $value;
    }

    /**
     * The quota setting, or with $fromStorage the quota defined in maildirsize.
     *
     * @return bool|array{size?: int, count?: int}
     * @throws Exception\RuntimeException When maildirsize is asked for but cannot be read.
     *
     * @mago-expect lint:no-boolean-flag-parameter The laminas-mail signature.
     */
    public function getQuota(bool $fromStorage = false): bool|array
    {
        if (! $fromStorage) {
            return $this->quota;
        }

        $contents = MaildirQuota::read($this->rootdir);
        if (null === $contents) {
            throw new Exception\RuntimeException('Cannot read maildirsize');
        }

        return MaildirQuota::parseDefinition(explode("\n", $contents)[0]);
    }

    /**
     * Whether the storage is over quota, or with $detailedResponse the usage and the quota.
     *
     * The usage comes from maildirsize, recalculated when it is missing, too
     * long, or says the storage is over quota.
     *
     * @return bool|array{size: int, count: int, quota: array{size?: int, count?: int}, over_quota: bool}
     * @throws Exception\ExceptionInterface When no quota is set or defined, or maildirsize cannot be written.
     *
     * @mago-expect lint:no-boolean-flag-parameter The laminas-mail signature.
     */
    public function checkQuota(bool $detailedResponse = false, bool $forceRecalc = false): bool|array
    {
        $contents = $forceRecalc ? null : MaildirQuota::read($this->rootdir);
        $usage    = null === $contents
            ? null
            : MaildirQuota::usage($contents, is_array($this->quota) ? $this->quota : null);
        if (null === $usage || MaildirQuota::isOver($usage)) {
            $usage = $this->calculateMaildirsize();
        }

        $result = [...$usage, 'over_quota' => MaildirQuota::isOver($usage)];

        return $detailedResponse ? $result : $result['over_quota'];
    }

    /**
     * The name relative to the root, checked.
     *
     * @throws Exception\InvalidArgumentException When the name is not allowed.
     * @throws Exception\RuntimeException When the name is INBOX's.
     */
    private function checkFolderName(string $name): string
    {
        $local = $this->localPath($name);
        if ('' === $local) {
            throw new Exception\RuntimeException("Folder {$name} already exists");
        }

        foreach (explode($this->delim, $local) as $part) {
            if (in_array($part, ['', '.', '..'], strict: true) || 1 === preg_match('/[\/\\\\\x00-\x1F\x7F]/', $part)) {
                throw new Exception\InvalidArgumentException(
                    "Invalid folder name {$name}: parts may not be empty, \".\" or \"..\", or hold \"/\", \"\\\" or control characters",
                );
            }
        }

        if (strlen($local) > 254) {
            throw new Exception\InvalidArgumentException("Invalid folder name {$name}: it is too long");
        }

        return $local;
    }

    private function folderExists(string $local): bool
    {
        try {
            $this->getFolders($local);

            return true;
        } catch (Exception\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * A folder that may be removed or renamed: not INBOX, and not the one selected or above it.
     *
     * @throws Exception\ExceptionInterface When there is no such folder, or it is INBOX or selected.
     */
    private function writableFolder(string $local, string $action): Folder
    {
        if ('' === $local) {
            throw new Exception\RuntimeException("Will not {$action} INBOX");
        }

        $folder  = $this->getFolders($local);
        $current = $this->localPath($this->currentFolder);
        if ($current === $local || str_starts_with($current, $local . $this->delim)) {
            throw new Exception\RuntimeException("Will not {$action} the selected folder");
        }

        return $folder;
    }

    /**
     * The name relative to the root of a folder that can hold messages.
     *
     * @throws Exception\ExceptionInterface When there is no such folder or it cannot hold messages.
     */
    private function selectableFolder(Folder|string $folder): string
    {
        $local = $this->localPath((string) $folder);
        if ('' !== $local && ! $this->getFolders($local)->isSelectable()) {
            throw new Exception\RuntimeException("{$folder} cannot hold messages");
        }

        return $local;
    }

    /**
     * A folder's tmp/, cur/ or new/, created where missing, refusing symbolic links.
     *
     * @throws Exception\RuntimeException When the folder or the directory is a symbolic link or cannot be created.
     */
    private function targetDirectory(string $local, string $subdir): string
    {
        $folder = $this->folderPath($local);
        if (is_link($folder)) {
            throw new Exception\RuntimeException('Will not write through a symbolic link');
        }

        FileSystem::createDirectory($folder . DIRECTORY_SEPARATOR . $subdir, $this->directoryMode);

        return $folder . DIRECTORY_SEPARATOR . $subdir;
    }

    /**
     * Note a stored message: in the message list when it is in the current folder, and in maildirsize.
     *
     * @param list<Flag|string> $flags
     */
    private function track(string $local, string $uniq, array $flags, string $filename, int $size): void
    {
        if ($this->localPath($this->currentFolder) === $local) {
            $this->files[] = ['uniq' => $uniq, 'flags' => $flags, 'filename' => $filename, 'size' => $size];
        }

        $this->addQuotaEntry($size, 1);
    }

    /**
     * @param list<Flag|string> $flags
     * @return list<Flag|string>
     */
    private static function withoutRecent(array $flags): array
    {
        return array_values(array_filter($flags, static fn(Flag|string $flag): bool => Flag::Recent !== $flag));
    }

    /**
     * @throws Exception\ExceptionInterface When quota checks are on and the storage is over quota.
     */
    private function refuseOverQuota(): void
    {
        if (false !== $this->quota && true === $this->checkQuota()) {
            throw new Exception\RuntimeException('The storage is over quota');
        }
    }

    /**
     * Append a "bytes messages" line to maildirsize, when quota checks are on.
     */
    private function addQuotaEntry(int $size, int $count): void
    {
        if (false !== $this->quota) {
            MaildirQuota::append($this->rootdir, $size, $count);
        }
    }

    /**
     * Count every message and write maildirsize afresh.
     *
     * @return array{size: int, count: int, quota: array{size?: int, count?: int}}
     * @throws Exception\ExceptionInterface When no quota is set or defined, or maildirsize cannot be written.
     */
    private function calculateMaildirsize(): array
    {
        $contents = MaildirQuota::read($this->rootdir);
        $quota    = is_array($this->quota) ? $this->quota : null;
        $quota    ??= null === $contents ? null : MaildirQuota::parseDefinition(explode("\n", $contents)[0]);
        if (null === $quota) {
            throw new Exception\RuntimeException('No quota is set or defined in maildirsize');
        }

        $usage = MaildirQuota::count($this->messageDirectories());
        $text  = MaildirQuota::definition($quota) . "\n{$usage['size']} {$usage['count']}\n";
        try {
            [$path] = MaildirDelivery::writeTemporary($this->targetDirectory('', 'tmp'), $text, $this->fileMode);

            // @codeCoverageIgnoreStart
            // Unreachable: only a composed message can fail to be written as MIME, and this is text
        } catch (MimeException $e) {
            throw new Exception\RuntimeException('Cannot write maildirsize', 0, $e);
        }

        // @codeCoverageIgnoreEnd

        MaildirQuota::replace($this->rootdir, $path, $usage['timestamps']);

        return ['size' => $usage['size'], 'count' => $usage['count'], 'quota' => $quota];
    }

    /**
     * The cur/ and new/ of every folder that holds messages.
     *
     * @return list<string>
     */
    private function messageDirectories(): array
    {
        $folders = [''];
        $tree    = new RecursiveIteratorIterator($this->rootFolder, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($tree as $folder) {
            if (! $folder->isSelectable() || 'INBOX' === $folder->getGlobalName()) {
                continue;
            }

            $folders[] = $folder->getGlobalName();
        }

        $directories = [];
        foreach ($folders as $local) {
            foreach (['cur', 'new'] as $subdir) {
                $directories[] = $this->folderPath($local) . DIRECTORY_SEPARATOR . $subdir;
            }
        }

        return $directories;
    }
}
