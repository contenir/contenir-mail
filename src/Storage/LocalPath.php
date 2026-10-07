<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\ConfigReader;

use function preg_match;
use function sprintf;
use function str_contains;

/**
 * Checks that a configured path names the local file system.
 *
 * PHP opens "scheme:" paths through stream wrappers (phar://, http://,
 * data:, php://...), which would let a configured path read remote data or
 * run a Phar's metadata through unserialize(). Storage paths must be plain
 * file system paths; a Windows drive letter ("C:\mail") is allowed.
 *
 * @internal Used by the storage *Config classes.
 */
final class LocalPath
{
    /**
     * @throws Exception\InvalidArgumentException When the path is empty, holds a NUL byte or names a stream wrapper.
     */
    public static function check(string $path, string $setting): string
    {
        if ('' === $path || str_contains($path, "\0") || 1 === preg_match('/^[a-z][a-z0-9+.\-]+:/i', $path)) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s must be a local file system path, without a stream wrapper such as "phar://"',
                $setting,
            ));
        }

        return $path;
    }

    /**
     * A setting the storage cannot work without.
     *
     * @throws Exception\InvalidArgumentException When the setting is missing.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When the setting is not a string.
     */
    public static function required(ConfigReader $reader, string $key, string $context): string
    {
        return (
            $reader->nullableString($key) ?? throw new Exception\InvalidArgumentException(sprintf(
                '%s: option "%s" is required',
                $context,
                $key,
            ))
        );
    }
}
