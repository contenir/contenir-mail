<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

use Contenir\Mail\Protocol\ErrorCapture;

use function fwrite;
use function get_resource_type;
use function is_resource;
use function sprintf;
use function strlen;

/**
 * Writes composed message text to a stream, failing loudly when the stream does not take all of it.
 *
 * @internal
 */
final class StreamOutput
{
    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

    /**
     * @throws Exception\InvalidArgumentException When $stream is not an open stream.
     */
    public static function check(mixed $stream): void
    {
        if (! is_resource($stream) || 'stream' !== get_resource_type($stream)) {
            throw new Exception\InvalidArgumentException('Expected an open stream');
        }
    }

    /**
     * @param resource $stream
     * @throws Exception\RuntimeException When the stream does not take all the bytes.
     */
    public static function write(mixed $stream, string $bytes): void
    {
        [$written, $warning] = ErrorCapture::run(static fn(): int|false => fwrite($stream, $bytes));
        if (strlen($bytes) !== $written) {
            throw new Exception\RuntimeException(sprintf(
                'Cannot write the message to the stream%s',
                '' === $warning ? '' : ": {$warning}",
            ));
        }
    }
}
