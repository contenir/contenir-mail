<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;

/**
 * Settings of a single maildir, without folders.
 *
 * ```php
 * new MaildirConfig(dirname: '/home/test/Maildir');
 * MaildirConfig::fromIterable(['dirname' => '/home/test/Maildir']);
 * ```
 *
 * @api
 */
final readonly class MaildirConfig
{
    public const array KEYS = ['dirname'];

    /**
     * @throws Exception\InvalidArgumentException When the directory is not a local path.
     */
    public function __construct(
        public string $dirname,
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

        return new self(LocalPath::required($reader, 'dirname', self::class));
    }
}
