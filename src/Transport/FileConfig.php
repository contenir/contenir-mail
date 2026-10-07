<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport;

use Closure;
use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;

use function is_dir;
use function is_link;
use function is_writable;
use function preg_match;
use function sprintf;
use function sys_get_temp_dir;

/**
 * Settings for the File transport.
 *
 * ```php
 * new FileConfig(path: '/var/mail-out', callback: static fn(File $transport): string => uniqid('mail_') . '.eml');
 * FileConfig::fromIterable(['path' => '/var/mail-out']);
 * ```
 *
 */
final readonly class FileConfig
{
    public const array KEYS = ['path', 'callback'];

    /** The directory mail files are written to */
    public string $path;

    /** @var Closure|null Called with the File transport; returns the next file name. */
    public ?Closure $callback;

    /**
     * @param string|null $path A writable local directory, not a symlink; the system temporary directory by default.
     * @param callable|null $callback Called with the File transport; returns the next file name. A random name by default.
     * @throws InvalidArgumentException When the path is a stream wrapper URL, a symlink, or not a writable directory.
     */
    public function __construct(?string $path = null, ?callable $callback = null)
    {
        $path ??= sys_get_temp_dir();
        if (1 === preg_match('/^[A-Za-z][A-Za-z0-9+.-]+:/', $path)) {
            throw new InvalidArgumentException(sprintf(
                'The mail file directory must be a local path, not a stream wrapper URL; received "%s"',
                $path,
            ));
        }

        if (is_link($path) || ! is_dir($path) || ! is_writable($path)) {
            throw new InvalidArgumentException(sprintf(
                'The mail file directory must be a writable directory and not a symlink; received "%s"',
                $path,
            ));
        }

        $this->path     = $path;
        $this->callback = null === $callback ? null : $callback(...);
    }

    /**
     * @param iterable<mixed, mixed> $config Keys "path" and "callback".
     * @throws InvalidArgumentException When a key is unknown or a value is invalid.
     */
    public static function fromIterable(iterable $config): self
    {
        $reader = ConfigReader::read(self::class, $config, self::KEYS);

        return new self($reader->nullableString('path'), $reader->callable('callback'));
    }
}
