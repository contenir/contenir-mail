<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Part;

use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\Window;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

#[CoversClass(Window::class)]
#[Group('unit')]
final class WindowTest extends TestCase
{
    #[Test]
    public function findsANeedleThatStraddlesBlocks(): void
    {
        $window = new Window(self::blocks(["ab\n-", '-b', 'x']));

        static::assertSame(2, $window->find("\n--b", from: 0));
    }

    #[Test]
    public function findsNothingPastTheLastBlock(): void
    {
        $window = new Window(self::blocks(['ab', "\n-", '-c']));

        static::assertNull($window->find("\n--b", from: 0));
    }

    #[Test]
    public function findsOnlyAtOrAfterTheGivenOffset(): void
    {
        $window = new Window(self::blocks(["\n--b\n--b"]));
        $window->find("\n--b", from: 0);

        static::assertSame(4, $window->find("\n--b", from: 1));
    }

    #[Test]
    public function readsTheByteBeforeAFoundNeedle(): void
    {
        $window = new Window(self::blocks(["a\r", "\n--b"]));
        $found  = (int) $window->find("\n--b", from: 0);

        static::assertSame("\r", $window->byte($found - 1));
    }

    #[Test]
    public function readsAPieceUpToItsLineBreak(): void
    {
        $window = new Window(self::blocks(['--b  ', "\r\nrest"]));

        static::assertSame("--b  \r\n", $window->piece(0));
    }

    #[Test]
    public function readsAPieceOfAtMostAChunk(): void
    {
        $window = new Window(self::blocks([str_repeat('a', times: Content::CHUNK + 5)]));

        static::assertSame(Content::CHUNK, strlen($window->piece(0)));
    }

    #[Test]
    public function readsAPieceToTheEndOfTheLastBlock(): void
    {
        $window = new Window(self::blocks(['x--b', '--']));

        static::assertSame('--b--', $window->piece(1));
    }

    /**
     * A piece needs at most a chunk past its start, so no more blocks are read than that takes.
     */
    #[Test]
    public function readsNoMoreBlocksThanAPieceNeeds(): void
    {
        $read   = 0;
        $window = new Window(self::counted(
            [str_repeat('a', times: Content::CHUNK), 'b', 'c'],
            $read,
        ));
        $window->piece(0);

        static::assertSame(1, $read);
    }

    /**
     * @param list<string> $blocks
     * @return Generator<int, string>
     */
    private static function blocks(array $blocks): Generator
    {
        $offset = 0;
        foreach ($blocks as $block) {
            yield $offset => $block;

            $offset += strlen($block);
        }
    }

    /**
     * @param list<string> $blocks
     * @return Generator<int, string> Counting each block read past.
     */
    private static function counted(array $blocks, int &$read): Generator
    {
        foreach ($blocks as $block) {
            yield $block;

            $read++;
        }
    }
}
