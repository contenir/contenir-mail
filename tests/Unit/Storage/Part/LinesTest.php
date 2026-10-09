<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Part;

use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\Lines;
use Contenir\Mail\Tests\Unit\TestAsset\Growth;
use Contenir\Mail\Tests\Unit\TestAsset\ShortReadStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fgets;
use function fopen;
use function ftell;
use function fwrite;
use function iterator_count;
use function iterator_to_array;
use function rewind;
use function str_repeat;
use function strlen;

#[CoversClass(Lines::class)]
#[CoversClass(Content::class)]
#[Group('unit')]
final class LinesTest extends TestCase
{
    /**
     * The pieces are those fgets() reads a chunk at a most at a time, wherever the blocks end.
     */
    #[DataProvider('textProvider')]
    #[Test]
    public function readsThePiecesFgetsWouldRead(string $text): void
    {
        static::assertSame(self::fgetsPieces($text), iterator_to_array(Lines::of(Content::fromString($text))));
    }

    #[DataProvider('textProvider')]
    #[Test]
    public function readsTheSamePiecesFromAStreamThatReturnsLittleAtATime(string $text): void
    {
        $content = Content::fromStream(ShortReadStream::open($text, readSize: 1000), start: 0, end: strlen($text));

        static::assertSame(self::fgetsPieces($text), iterator_to_array(Lines::of($content)));
    }

    /**
     * A piece is given as soon as it is known, before the next block is read.
     */
    #[Test]
    public function readsNoFurtherThanThePieceGiven(): void
    {
        $stream = ShortReadStream::open(str_repeat('a', times: Content::CHUNK * 2), readSize: Content::CHUNK);
        $pieces = Lines::of(Content::fromStream($stream, start: 0, end: Content::CHUNK * 2));
        $pieces->current();

        static::assertSame(Content::CHUNK, ftell($stream));
    }

    #[Test]
    public function readsNothingFromEmptyContent(): void
    {
        static::assertSame([], iterator_to_array(Lines::of(Content::fromString(''))));
    }

    #[Test]
    public function rejoinsBlocksAtLineBreaks(): void
    {
        static::assertSame(
            ["a\n", "bc\n", "d\ne\n", 'f'],
            iterator_to_array(Lines::whole(["a\nb", "c\nd", "\ne\nf"]), preserve_keys: false),
        );
    }

    #[Test]
    public function rejoinsBlocksWithoutLineBreaksIntoTheLast(): void
    {
        static::assertSame(['abc'], iterator_to_array(Lines::whole(['a', 'b', 'c']), preserve_keys: false));
    }

    /**
     * A line longer than a block is read in chunks, without scanning the rest of it for each.
     */
    #[Group('slow')]
    #[Test]
    public function readsALongLineInLinearTime(): void
    {
        $ratio = Growth::ratio(
            static fn(int $size): Content => Content::fromString(str_repeat('a', times: $size * 100)),
            static fn(Content $content): int => iterator_count(Lines::of($content)),
            size: 2_000,
            factor: 8,
        );

        static::assertLessThan(32, $ratio, 'Reading a line 8 times as long took over 32 times as long');
    }

    #[Group('slow')]
    #[Test]
    public function readsManyLinesInLinearTime(): void
    {
        $ratio = Growth::ratio(
            static fn(int $size): Content => Content::fromString(str_repeat("a line\r\n", times: $size * 10)),
            static fn(Content $content): int => iterator_count(Lines::of($content)),
            size: 2_500,
            factor: 8,
        );

        static::assertLessThan(32, $ratio, 'Reading 8 times as many lines took over 32 times as long');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function textProvider(): array
    {
        return [
            'short lines'                     => ["a\nbc\r\nd"],
            'ends with a line break'          => ["a\nb\n"],
            'line of exactly a chunk'         => [str_repeat('a', times: Content::CHUNK - 1) . "\nb"],
            'line a byte longer than a chunk' => [str_repeat('a', times: Content::CHUNK) . "\nb"],
            'chunk-long text without a break' => [str_repeat('a', times: Content::CHUNK)],
            'line across the block end'       => [str_repeat('a', times: Content::BLOCK - 2) . "\nbcd\ne"],
            'line break at the block end'     => [str_repeat('a', times: Content::BLOCK - 1) . "\nb"],
            'line break after the block end'  => [str_repeat('a', times: Content::BLOCK) . "\nb"],
            'CRLF across the block end'       => [str_repeat('a', times: Content::BLOCK - 1) . "\r\nb\r\n"],
            'line longer than a block'        => [str_repeat('a', times: Content::BLOCK + Content::CHUNK + 5) . "\nb"],
            'many lines over blocks'          => [str_repeat("line of text\r\n", times: 6_000)],
            'chunk boundary meets block end'  => [
                str_repeat('a', times: Content::BLOCK - 10) . str_repeat('b', times: Content::CHUNK),
            ],
        ];
    }

    /**
     * The pieces as Content::lines() read them before reading in blocks: fgets() of a chunk at most.
     *
     * @return array<int, string>
     */
    private static function fgetsPieces(string $text): array
    {
        $stream = fopen('php://memory', mode: 'w+b');
        fwrite($stream, $text);
        rewind($stream);
        $pieces = [];
        $offset = 0;
        while ($offset < strlen($text)) {
            $piece           = (string) fgets($stream, Content::CHUNK + 1);
            $pieces[$offset] = $piece;
            $offset          += strlen($piece);
        }

        return $pieces;
    }
}
