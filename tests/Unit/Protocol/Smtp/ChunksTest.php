<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp;

use ArrayObject;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Smtp\Chunks;
use Contenir\Mail\Tests\TestAsset\RecordingWriteStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function fopen;
use function fwrite;
use function implode;
use function iterator_to_array;
use function str_repeat;
use function strlen;

#[CoversClass(Chunks::class)]
#[Group('unit')]
final class ChunksTest extends TestCase
{
    /**
     * @param list<int> $sizes
     */
    #[DataProvider('sizeProvider')]
    #[Test]
    public function splitsStringIntoChunksOfTheSize(int $length, array $sizes): void
    {
        static::assertSame($sizes, array_map(strlen(...), iterator_to_array(
            Chunks::ofString(str_repeat('a', $length)),
            preserve_keys: false,
        )));
    }

    #[Test]
    public function keepsTheBytesOfTheString(): void
    {
        $data = str_repeat('0123456789', times: 10_000);

        static::assertSame($data, implode('', iterator_to_array(Chunks::ofString($data), preserve_keys: false)));
    }

    /**
     * @param list<int> $sizes
     */
    #[DataProvider('sizeProvider')]
    #[Test]
    public function readsStreamInChunksOfTheSize(int $length, array $sizes): void
    {
        static::assertSame($sizes, array_map(strlen(...), iterator_to_array(
            Chunks::ofStream(self::stream(str_repeat('a', $length))),
            preserve_keys: false,
        )));
    }

    #[Test]
    public function readsStreamFromItsStart(): void
    {
        static::assertSame(['abc'], iterator_to_array(Chunks::ofStream(self::stream('abc')), preserve_keys: false));
    }

    #[Test]
    public function failsOnStreamThatCannotBeRead(): void
    {
        $chunks = Chunks::ofStream(RecordingWriteStream::open(new ArrayObject(), accept: 1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read the message stream');

        iterator_to_array($chunks);
    }

    /**
     * @return array<string, array{int, list<int>}>
     */
    public static function sizeProvider(): array
    {
        return [
            'nothing'               => [0, []],
            'one byte'              => [1, [1]],
            'one chunk'             => [Chunks::SIZE, [Chunks::SIZE]],
            'one byte over a chunk' => [Chunks::SIZE + 1, [Chunks::SIZE, 1]],
            'two chunks'            => [Chunks::SIZE * 2, [Chunks::SIZE, Chunks::SIZE]],
        ];
    }

    /**
     * @return resource
     */
    private static function stream(string $content)
    {
        $stream = fopen('php://memory', mode: 'w+b');
        static::assertNotFalse($stream);
        fwrite($stream, $content);

        return $stream;
    }
}
