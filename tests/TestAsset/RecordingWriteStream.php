<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset;

use ArrayObject;
use RuntimeException;

use function fopen;
use function in_array;
use function is_int;
use function min;
use function stream_context_create;
use function stream_context_get_options;
use function stream_get_wrappers;
use function stream_set_chunk_size;
use function stream_wrapper_register;
use function strlen;

/**
 * A seekable, write-only stream that records the size of each write it is given and
 * takes at most a fixed number of bytes from each fwrite(), as a busy socket may.
 *
 * It cannot be read: fread() fails on it. The record and the write size travel in the
 * stream context, so no state is shared between streams or tests.
 */
final class RecordingWriteStream
{
    public const string PROTOCOL = 'contenir-recording-write';

    /** @var resource|null Set by PHP when the stream is opened with a context */
    public $context;

    /** @var ArrayObject<int, int> */
    private ArrayObject $writes;

    private int $accept = 1;

    /** Whether the last write took bytes, so that the next one ends PHP's write loop */
    private bool $tookBytes = false;

    private int $position = 0;

    /**
     * @param ArrayObject<int, int> $writes Receives the number of bytes offered to each write.
     * @return resource
     */
    public static function open(ArrayObject $writes, int $accept)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), strict: true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $context = stream_context_create([self::PROTOCOL => ['writes' => $writes, 'accept' => $accept]]);
        $stream  = fopen(self::PROTOCOL . '://sink', mode: 'wb', use_include_path: false, context: $context);
        if (false === $stream) {
            throw new RuntimeException('Cannot open a recording stream');
        }

        stream_set_chunk_size($stream, 1 << 20);

        return $stream;
    }

    /** @mago-expect lint:method-name PHP calls stream wrapper methods by these names. */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $contextOptions = null === $this->context ? [] : stream_context_get_options($this->context);
        $writes         = $contextOptions[self::PROTOCOL]['writes'] ?? null;
        $accept         = $contextOptions[self::PROTOCOL]['accept'] ?? null;
        if (! $writes instanceof ArrayObject || ! is_int($accept) || $accept < 1) {
            return false;
        }

        $this->writes = $writes;
        $this->accept = $accept;

        return true;
    }

    /**
     * Take up to the accepted size, then nothing on the next call, so that each fwrite() is short.
     *
     * @mago-expect lint:method-name PHP calls stream wrapper methods by these names.
     */
    public function stream_write(string $data): int
    {
        if ($this->tookBytes) {
            $this->tookBytes = false;

            return 0;
        }

        $this->writes[]  = strlen($data);
        $taken           = min(strlen($data), $this->accept);
        $this->position  += $taken;
        $this->tookBytes = true;

        return $taken;
    }

    /** @mago-expect lint:method-name PHP calls stream wrapper methods by these names. */
    public function stream_seek(int $offset, int $whence): bool
    {
        return true;
    }

    /** @mago-expect lint:method-name PHP calls stream wrapper methods by these names. */
    public function stream_tell(): int
    {
        return $this->position;
    }

    /** @mago-expect lint:method-name PHP calls stream wrapper methods by these names. */
    public function stream_eof(): bool
    {
        return true;
    }

    /** @mago-expect lint:method-name PHP calls stream wrapper methods by these names. */
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return true;
    }
}
