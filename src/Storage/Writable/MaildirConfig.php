<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Writable;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\Folder;
use Contenir\Mail\Storage\LocalPath;

use function sprintf;

/**
 * Settings of a writable Maildir++ tree.
 *
 * Directories and files it creates are private to the owner by default
 * (0700 and 0600), as Maildir delivery agents create them; the process
 * umask can only take permissions away from these modes.
 *
 * ```php
 * new MaildirConfig(dirname: '/home/test/Maildir', create: true);
 * MaildirConfig::fromIterable(['dirname' => '/home/test/Maildir', 'create' => true, 'file_mode' => 0o640]);
 * ```
 *
 * @mago-expect lint:excessive-parameter-list A value object built with named arguments; every setting but the directory is optional.
 *
 * @api
 */
final readonly class MaildirConfig
{
    public const array KEYS = ['dirname', 'delim', 'folder', 'create', 'directory_mode', 'file_mode'];

    /**
     * @param bool $create Create the maildir when the directory has no cur/.
     * @param int $directoryMode Permissions of directories created, 0 to 0777.
     * @param int $fileMode Permissions of message and quota files created, 0 to 0777.
     * @throws Exception\InvalidArgumentException When the directory is not a local path, the delimiter is unsafe or a mode is out of range.
     */
    public function __construct(
        public string $dirname,
        public string $delim = '.',
        public string $folder = 'INBOX',
        public bool $create = false,
        public int $directoryMode = 0o700,
        public int $fileMode = 0o600,
    ) {
        LocalPath::check($dirname, 'dirname');
        Folder\MaildirConfig::checkDelimiter($delim);
        self::checkMode($directoryMode, 'directory_mode');
        self::checkMode($fileMode, 'file_mode');
    }

    /**
     * @param iterable<mixed, mixed> $config
     * @throws InvalidArgumentException When a key is unknown or missing, or a value has the wrong type.
     */
    public static function fromIterable(iterable $config): self
    {
        $reader = ConfigReader::read(self::class, $config, self::KEYS);

        return new self(
            dirname: $reader->requiredString('dirname'),
            delim: $reader->string('delim', default: '.'),
            folder: $reader->string('folder', default: 'INBOX'),
            create: $reader->bool('create', default: false),
            directoryMode: $reader->int('directory_mode', default: 0o700),
            fileMode: $reader->int('file_mode', default: 0o600),
        );
    }

    /**
     * The settings that reading the tree needs.
     */
    public function folderConfig(): Folder\MaildirConfig
    {
        return new Folder\MaildirConfig($this->dirname, $this->delim, $this->folder);
    }

    /**
     * @throws Exception\InvalidArgumentException When the mode is outside 0 to 0777.
     */
    private static function checkMode(int $mode, string $setting): void
    {
        if ($mode < 0 || $mode > 0o777) {
            throw new Exception\InvalidArgumentException(sprintf('%s must be between 0 and 0777', $setting));
        }
    }
}
