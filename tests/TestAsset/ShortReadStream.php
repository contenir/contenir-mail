<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset;

use RuntimeException;

use function fopen;
use function in_array;
use function is_int;
use function is_string;
use function min;
use function stream_context_create;
use function stream_context_get_options;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function strlen;
use function substr;

use const SEEK_SET;

/**
 * A read-only stream that returns at most a fixed number of bytes from each
 * read, however many are asked for, as sockets and filtered streams may.
 *
 * The content and read size travel in the stream context, so no state is
 * shared between streams or tests.
 */
final class ShortReadStream
{
    public const string PROTOCOL = 'contenir-short-read';

    /** @var resource|null Set by PHP when the stream is opened with a context */
    public $context;

    private string $content = '';

    private int $readSize = 1;

    private int $position = 0;

    /**
     * @return resource
     */
    public static function open(string $content, int $readSize)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), strict: true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $context = stream_context_create([self::PROTOCOL => ['content' => $content, 'readSize' => $readSize]]);
        $stream  = fopen(self::PROTOCOL . '://content', mode: 'rb', use_include_path: false, context: $context);
        if (false === $stream) {
            throw new RuntimeException('Cannot open a short-read stream');
        }

        return $stream;
    }

    /** @mago-expect lint:method-name PHP calls stream wrapper methods by these names. */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $contextOptions = null === $this->context ? [] : stream_context_get_options($this->context);
        $content        = $contextOptions[self::PROTOCOL]['content'] ?? null;
        $size           = $contextOptions[self::PROTOCOL]['readSize'] ?? null;
        if (! is_string($content) || ! is_int($size) || $size < 1) {
            return false;
        }

        $this->content  = $content;
        $this->readSize = $size;

        return true;
    }

    /** @mago-expect lint:method-name PHP calls stream wrapper methods by these names. */
    public function stream_read(int $count): string
    {
        $chunk          = substr($this->content, $this->position, min($count, $this->readSize));
        $this->position += strlen($chunk);

        return $chunk;
    }

    /** @mago-expect lint:method-name PHP calls stream wrapper methods by these names. */
    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->content);
    }

    /** @mago-expect lint:method-name PHP calls stream wrapper methods by these names. */
    public function stream_seek(int $offset, int $whence): bool
    {
        if (SEEK_SET !== $whence || $offset < 0 || $offset > strlen($this->content)) {
            return false;
        }

        $this->position = $offset;

        return true;
    }

    /** @mago-expect lint:method-name PHP calls stream wrapper methods by these names. */
    public function stream_tell(): int
    {
        return $this->position;
    }

    /**
     * @return array<string, int>
     *
     * @mago-expect lint:method-name PHP calls stream wrapper methods by these names.
     */
    public function stream_stat(): array
    {
        return ['size' => strlen($this->content)];
    }
}
