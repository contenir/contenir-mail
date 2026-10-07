<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Folder;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\LocalPath;

use function in_array;
use function strlen;

/**
 * Settings of a Maildir++ tree: a maildir whose folders are ".Name" subdirectories.
 *
 * ```php
 * new MaildirConfig(dirname: '/home/test/Maildir', folder: 'INBOX.Archive');
 * MaildirConfig::fromIterable(['dirname' => '/home/test/Maildir', 'delim' => '.']);
 * ```
 *
 * @api
 */
final readonly class MaildirConfig
{
    public const array KEYS = ['dirname', 'delim', 'folder'];

    /**
     * @param string $delim The one character between folder names, "." in Maildir++.
     * @param string $folder The folder selected first, by its global name.
     * @throws Exception\InvalidArgumentException When the directory is not a local path or the delimiter is not one safe character.
     */
    public function __construct(
        public string $dirname,
        public string $delim = '.',
        public string $folder = 'INBOX',
    ) {
        LocalPath::check($dirname, 'dirname');
        self::checkDelimiter($delim);
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
        );
    }

    /**
     * The delimiter becomes part of directory names, so it may not be a path separator.
     *
     * @internal
     * @throws Exception\InvalidArgumentException When the delimiter is not one character, or is "/", "\" or NUL.
     */
    public static function checkDelimiter(string $delim): void
    {
        if (1 !== strlen($delim) || in_array($delim, ['/', '\\', "\0"], strict: true)) {
            throw new Exception\InvalidArgumentException(
                'delim must be one character other than "/", "\\" and NUL',
            );
        }
    }
}
