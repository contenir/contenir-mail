<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp;

use Contenir\Mail\Protocol\ErrorCapture;
use Contenir\Mail\Protocol\Exception;
use Generator;

use function fread;
use function rewind;
use function strlen;
use function substr;

/**
 * Message text in chunks of at most SIZE bytes, from a string or a stream, for MessageData to work on.
 *
 * @internal
 */
final class Chunks
{
    /** Bytes of message text read and written at a time */
    public const int SIZE = 65_536;

    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

    /**
     * The stream from its start to its end.
     *
     * @param resource $stream
     * @return Generator<int, string>
     * @throws Exception\RuntimeException When the stream cannot be read.
     */
    public static function ofStream(mixed $stream): Generator
    {
        rewind($stream);
        while (true) {
            [$chunk] = ErrorCapture::run(static fn(): string|false => fread($stream, self::SIZE));
            if (false === $chunk) {
                throw new Exception\RuntimeException('Cannot read the message stream');
            }

            if ('' === $chunk) {
                return;
            }

            yield $chunk;
        }
    }

    /**
     * @return Generator<int, string>
     */
    public static function ofString(string $data): Generator
    {
        $length = strlen($data);
        for ($offset = 0; $offset < $length; $offset += self::SIZE) {
            yield substr($data, $offset, self::SIZE);
        }
    }
}
