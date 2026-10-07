<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;

/**
 * Settings of a single mbox file.
 *
 * ```php
 * new MboxConfig(filename: '/home/test/mail/inbox');
 * MboxConfig::fromIterable(['filename' => '/home/test/mail/inbox', 'format' => 'mboxrd']);
 * ```
 *
 * @api
 */
final readonly class MboxConfig
{
    public const array KEYS = ['filename', 'format'];

    /**
     * @throws Exception\InvalidArgumentException When the filename is not a local path.
     */
    public function __construct(
        public string $filename,
        public MboxFormat $format = MboxFormat::Mboxo,
    ) {
        LocalPath::check($filename, 'filename');
    }

    /**
     * @param iterable<mixed, mixed> $config
     * @throws InvalidArgumentException When a key is unknown or missing, or a value has the wrong type.
     */
    public static function fromIterable(iterable $config): self
    {
        $reader = ConfigReader::read(self::class, $config, self::KEYS);

        return new self(
            filename: $reader->requiredString('filename'),
            format: $reader->enum('format', default: MboxFormat::Mboxo),
        );
    }
}
