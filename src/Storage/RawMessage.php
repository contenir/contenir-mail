<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\Message as ComposedMessage;
use Contenir\Mail\Mime\Exception\RuntimeException as MimeException;

use function fwrite;
use function get_resource_type;
use function is_resource;
use function is_string;
use function stream_copy_to_stream;
use function stream_get_contents;

/**
 * A message to store, given as its text, a stream, a read message or a composed one.
 *
 * @internal Used by Imap and Writable\Maildir.
 */
final class RawMessage
{
    /**
     * @throws Exception\InvalidArgumentException When the message is of an unknown kind.
     * @throws Exception\RuntimeException When a read message's body cannot be read.
     * @throws MimeException When a composed message cannot be written.
     */
    public static function toString(mixed $message): string
    {
        return match (true) {
            is_string($message) => $message,
            is_resource($message) && 'stream' === get_resource_type($message) => (string) stream_get_contents($message),
            $message instanceof Message || $message instanceof ComposedMessage => $message->toString(),
            default => throw self::unknown(),
        };
    }

    /**
     * Write the message to a stream, copying a stream without reading it into memory.
     *
     * @param resource $target
     * @throws Exception\InvalidArgumentException When the message is of an unknown kind.
     * @throws Exception\RuntimeException When a read message's body cannot be read, or writing fails.
     * @throws MimeException When a composed message cannot be written.
     *
     * @mago-expect analysis:missing-parameter-type Streams have no native parameter type.
     */
    public static function write(mixed $message, $target): void
    {
        $written = is_resource($message)
            ? self::copy($message, $target)
            : self::put(self::toString($message), $target);
        if (false === $written) {
            throw new Exception\RuntimeException('Cannot write the message');
        }
    }

    /**
     * @param resource $source
     * @param resource $target
     *
     * @mago-expect analysis:missing-parameter-type Streams have no native parameter type.
     */
    private static function copy($source, $target): int|false
    {
        return FileSystem::quietly(static fn(): int|false => stream_copy_to_stream($source, $target));
    }

    /**
     * @param resource $target
     *
     * @mago-expect analysis:missing-parameter-type Streams have no native parameter type.
     */
    private static function put(string $text, $target): int|false
    {
        return FileSystem::quietly(static fn(): int|false => fwrite($target, $text));
    }

    private static function unknown(): Exception\InvalidArgumentException
    {
        return new Exception\InvalidArgumentException('A message must be a string, a stream, or a message object');
    }
}
