<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Writable;

use Contenir\Mail\Mime\Exception\RuntimeException as MimeException;
use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\FileSystem;
use Contenir\Mail\Storage\RawMessage;

use function chmod;
use function fclose;
use function fflush;
use function fopen;
use function fsync;
use function link;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * The file system steps of Maildir delivery.
 *
 * A message is written to a new file in tmp/, opened exclusively so no
 * existing file or symbolic link of that name is followed, made private,
 * synced to disk, and only then linked into cur/ or new/.
 *
 * @internal Used by Writable\Maildir.
 */
final class MaildirDelivery
{
    /**
     * Write a message to a new file in a tmp/ directory, synced to disk.
     *
     * The unique name holds 64 random bits, so it is never taken; when the
     * file cannot be created, trying again would not help.
     *
     * @return array{string, string} The file and its unique name.
     * @throws Exception\ExceptionInterface When the message is of an unknown kind or cannot be written.
     * @throws MimeException When a composed message cannot be written.
     */
    public static function writeTemporary(string $directory, mixed $message, int $fileMode): array
    {
        $name   = MaildirName::unique();
        $path   = $directory . DIRECTORY_SEPARATOR . $name;
        $handle = FileSystem::quietly(static fn(): mixed => fopen($path, mode: 'xb'));
        if (false === $handle) {
            throw new Exception\RuntimeException("Cannot create a temporary file in {$directory}");
        }

        FileSystem::quietly(static fn(): bool => chmod($path, $fileMode));
        try {
            RawMessage::write($message, $handle);
            fflush($handle);
            fsync($handle);
        } catch (Exception\ExceptionInterface|MimeException $e) {
            fclose($handle);
            FileSystem::quietly(static fn(): bool => unlink($path));

            throw $e;
        }

        fclose($handle);

        return [$path, $name];
    }

    /**
     * Link a written temporary file into place, and remove it from tmp/.
     *
     * @throws Exception\RuntimeException When linking fails, as when the target name is taken.
     */
    public static function deliver(string $path, string $target): void
    {
        $linked = FileSystem::quietly(static fn(): bool => link($path, $target));
        FileSystem::quietly(static fn(): bool => unlink($path));
        if (! $linked) {
            throw new Exception\RuntimeException('Cannot link the message file into the folder');
        }
    }
}
