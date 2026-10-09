<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Part;

use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\FileSystem;
use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\Lines;
use Contenir\Mail\Tests\TestAsset\ShortReadStream;
use Contenir\Mail\Tests\Trait\UsesTemporaryDirectoryTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function file_put_contents;
use function fopen;
use function fread;
use function fseek;
use function fwrite;
use function get_resources;
use function implode;
use function iterator_to_array;
use function str_repeat;
use function strlen;
use function unlink;

#[CoversClass(Content::class)]
#[CoversClass(Lines::class)]
#[CoversClass(FileSystem::class)]
#[Group('unit')]
final class ContentTest extends TestCase
{
    use UsesTemporaryDirectoryTrait;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    #[Test]
    public function readsBlocksKeyedByTheirOffset(): void
    {
        $blocks = iterator_to_array(Content::fromString(str_repeat('a', times: Content::BLOCK + 3))->blocks());

        static::assertSame([0 => Content::BLOCK, Content::BLOCK => 3], array_map(strlen(...), $blocks));
    }

    #[Test]
    public function readsBlocksOnlyWithinTheRange(): void
    {
        $content = Content::fromString('abcdef')->slice(1, 4);

        static::assertSame([0 => 'bcd'], iterator_to_array($content->blocks()));
    }

    #[Test]
    public function readsBlocksFromAStreamThatReturnsLessThanAskedFor(): void
    {
        $stream = ShortReadStream::open('abcdefgh', readSize: 3);

        static::assertSame(
            [0 => 'abc', 3 => 'def', 6 => 'gh'],
            iterator_to_array(Content::fromStream($stream, start: 0, end: 8)->blocks()),
        );
    }

    /**
     * Ranges of an mbox share its stream, so reading one moves the stream under another.
     */
    #[Test]
    public function readsOnWhereItWasWhenAnotherReaderMovedTheStream(): void
    {
        $text   = str_repeat('a', times: Content::BLOCK) . 'b';
        $stream = fopen('php://temp', mode: 'w+b');
        fwrite($stream, $text);
        $blocks = Content::fromStream($stream, start: 0, end: strlen($text))->blocks();
        $blocks->current();
        fseek($stream, offset: 0);
        fread($stream, length: 10);
        $blocks->next();

        static::assertSame('b', $blocks->current());
    }

    #[Test]
    public function readsLinesOnWhereItWasWhenAnotherReaderMovedTheStream(): void
    {
        $text   = str_repeat("line\n", times: 20_000);
        $stream = fopen('php://temp', mode: 'w+b');
        fwrite($stream, $text);
        $first  = Content::fromStream($stream, start: 0, end: strlen($text));
        $second = Content::fromStream($stream, start: 0, end: 5);
        $pieces = [];
        foreach (Lines::of($first) as $piece) {
            $second->read();
            $pieces[] = $piece;
        }

        static::assertSame($text, implode('', $pieces));
    }

    #[Test]
    public function readsAWholeFile(): void
    {
        $path = $this->file('Subject: x');

        static::assertSame('Subject: x', Content::fromFile($path, start: 0, end: 10)->read());
    }

    #[Test]
    public function readsARangeOfAFile(): void
    {
        $path = $this->file('abcdef');

        static::assertSame('cd', Content::fromFile($path, start: 0, end: 6)->slice(2, 4)->read());
    }

    #[Test]
    public function measuresAFileWithoutOpeningIt(): void
    {
        static::assertSame(4, Content::fromFile("{$this->directory}/missing", start: 2, end: 6)->length());
    }

    #[Test]
    public function slicesAFileWithoutOpeningIt(): void
    {
        static::assertSame(2, Content::fromFile("{$this->directory}/missing", start: 0, end: 6)->slice(1, 3)->length());
    }

    #[Test]
    public function treatsAFileRangeEndingBeforeItsStartAsEmpty(): void
    {
        static::assertSame(0, Content::fromFile("{$this->directory}/missing", start: 3, end: 1)->length());
    }

    #[Test]
    public function holdsNoOpenFileBetweenReads(): void
    {
        $path    = $this->file('abc');
        $before  = count(get_resources('stream'));
        $content = Content::fromFile($path, start: 0, end: 3);
        $content->read();

        static::assertSame($before, count(get_resources('stream')));
    }

    #[Test]
    public function closesTheFileWhenReadingStopsEarly(): void
    {
        $path   = $this->file(str_repeat("a\n", times: 100));
        $before = count(get_resources('stream'));
        $lines  = Lines::of(Content::fromFile($path, start: 0, end: 200));
        $lines->current();
        unset($lines);

        static::assertSame($before, count(get_resources('stream')));
    }

    #[Test]
    public function readsAFileAgainAfterReadingIt(): void
    {
        $content = Content::fromFile($this->file('abc'), start: 0, end: 3);
        $content->read();

        static::assertSame('abc', $content->read());
    }

    #[Test]
    public function refusesToReadAFileThatHasGone(): void
    {
        $path    = $this->file('abc');
        $content = Content::fromFile($path, start: 0, end: 3);
        unlink($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot open the message file; it may have been moved');

        $content->read();
    }

    #[Test]
    public function readsMboxrdChunksEndingAtLineBreaks(): void
    {
        $text   = str_repeat('a', times: Content::BLOCK - 1) . "\n>From b\nc";
        $stream = fopen('php://temp', mode: 'w+b');
        fwrite($stream, $text);

        static::assertSame(
            [str_repeat('a', times: Content::BLOCK - 1) . "\n", "From b\n", 'c'],
            iterator_to_array(
                Content::fromStream($stream, 0, strlen($text), unquoteFrom: true)->chunks(),
                preserve_keys: false,
            ),
        );
    }

    #[Test]
    public function readsOtherChunksAsBlocks(): void
    {
        $text = str_repeat('a', times: Content::BLOCK - 1) . "\n>From b\nc";

        static::assertSame(
            [0 => Content::BLOCK, 1 => 9],
            array_map(strlen(...), iterator_to_array(Content::fromString($text)->chunks(), preserve_keys: false)),
        );
    }

    #[Test]
    public function unquotesAFromLineThatStraddlesBlocks(): void
    {
        $text   = str_repeat('a', times: Content::BLOCK - 3) . "\n>From b\n";
        $stream = fopen('php://temp', mode: 'w+b');
        fwrite($stream, $text);

        static::assertSame(
            str_repeat('a', times: Content::BLOCK - 3) . "\nFrom b\n",
            Content::fromStream($stream, 0, strlen($text), unquoteFrom: true)->read(),
        );
    }

    #[Test]
    public function keepsChunksOfALongMboxrdLineTogether(): void
    {
        $line   = str_repeat('a', times: Content::BLOCK + 1);
        $stream = fopen('php://temp', mode: 'w+b');
        fwrite($stream, ">From x\n{$line}\n");

        static::assertSame(
            ["From x\n", "{$line}\n", ''],
            iterator_to_array(
                Content::fromStream($stream, 0, Content::BLOCK + 10, unquoteFrom: true)->chunks(),
                preserve_keys: false,
            ),
        );
    }

    private function file(string $content): string
    {
        $path = "{$this->directory}/message";
        file_put_contents($path, $content);

        return $path;
    }
}
