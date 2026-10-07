<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Folder;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\LocalPath;
use Contenir\Mail\Storage\MboxFormat;

/**
 * Settings of a directory tree of mbox files, one per folder.
 *
 * ```php
 * new MboxConfig(dirname: '/home/test/mail', folder: 'Archive');
 * MboxConfig::fromIterable(['dirname' => '/home/test/mail']);
 * ```
 *
 * @api
 */
final readonly class MboxConfig
{
    public const array KEYS = ['dirname', 'folder', 'format'];

    /**
     * @param string $folder The folder selected first, by its global name.
     * @throws Exception\InvalidArgumentException When the directory is not a local path.
     */
    public function __construct(
        public string $dirname,
        public string $folder = 'INBOX',
        public MboxFormat $format = MboxFormat::Mboxo,
    ) {
        LocalPath::check($dirname, 'dirname');
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
            folder: $reader->string('folder', default: 'INBOX'),
            format: $reader->enum('format', default: MboxFormat::Mboxo),
        );
    }
}
