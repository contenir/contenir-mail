<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Part;

use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\Part;
use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\Lines;
use Contenir\Mail\Storage\Part\MultipartSplitter;
use Contenir\Mail\Storage\Part\Window;
use Contenir\Mail\Tests\Unit\TestAsset\Growth;
use Contenir\Mail\Tests\Unit\TestAsset\ShortReadStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function str_repeat;
use function strlen;

#[CoversClass(MultipartSplitter::class)]
#[CoversClass(Window::class)]
#[CoversClass(Content::class)]
#[CoversClass(Lines::class)]
#[Group('unit')]
final class MultipartSplitterTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('bodyProvider')]
    #[Test]
    public function splitsBodyIntoParts(string $body, array $expected): void
    {
        static::assertSame($expected, self::parts(Content::fromString($body)));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('bodyProvider')]
    #[Test]
    public function splitsBodyReadALittleAtATime(string $body, array $expected): void
    {
        $content = Content::fromStream(ShortReadStream::open($body, readSize: 777), start: 0, end: strlen($body));

        static::assertSame($expected, self::parts($content));
    }

    /**
     * A boundary line is found wherever the blocks the body is read in end, and the line
     * break before it is left out of the part, whether or not it is split from the boundary.
     */
    #[DataProvider('blockEdgeProvider')]
    #[Test]
    public function findsBoundaryAcrossTheEndOfABlock(int $fill, string $lineBreak): void
    {
        $first = str_repeat('x', times: $fill);
        $body  = "--b{$lineBreak}{$first}{$lineBreak}--b{$lineBreak}second{$lineBreak}--b--";

        static::assertSame([$first, 'second'], self::parts(Content::fromString($body)));
    }

    #[Test]
    public function readsBoundaryWithTransportPaddingLongerThanAChunkAsOld(): void
    {
        $padding = str_repeat(' ', times: Content::CHUNK + 10);
        $body    = "--b{$padding}\r\npart\r\n--b--";

        static::assertSame([str_repeat(' ', times: 13) . "\r\npart"], self::parts(Content::fromString($body)));
    }

    #[Test]
    public function refusesMoreThanTheMostParts(): void
    {
        $body = str_repeat("--b\r\nx\r\n", times: MultipartSplitter::MAX_PARTS + 1) . '--b--';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A multipart may hold at most 1000 parts');

        MultipartSplitter::split(Content::fromString($body), 'b');
    }

    #[Test]
    public function readsNestedMultipartsAcrossBlocks(): void
    {
        $inner = "--i\r\n\r\n" . str_repeat('y', times: Content::BLOCK) . "\r\n--i\r\n\r\nz\r\n--i--";
        $outer = Part::fromString(
            "Content-Type: multipart/mixed; boundary=o\r\n\r\npreamble\r\n--o\r\n"
                . "Content-Type: multipart/alternative; boundary=i\r\n\r\n{$inner}\r\n--o\r\n\r\nlast\r\n--o--\r\nepilogue",
        );

        static::assertSame(
            [Content::BLOCK, 1, 4],
            [
                strlen($outer->getPart(1)->getPart(1)->getContent()),
                strlen($outer->getPart(1)->getPart(2)->getContent()),
                strlen($outer->getPart(2)->getContent()),
            ],
        );
    }

    /**
     * Only the lines that start with the boundary are looked at, so lines are not read one by one.
     */
    #[Group('slow')]
    #[Test]
    public function splitsInLinearTime(): void
    {
        $ratio = Growth::ratio(
            static fn(int $size): Content => Content::fromString(
                "--b\r\n" . str_repeat("a line of the first part\r\n", times: $size * 10) . "--b\r\nx\r\n--b--",
            ),
            static fn(Content $content): array => MultipartSplitter::split($content, 'b'),
            size: 900,
            factor: 8,
        );

        static::assertLessThan(32, $ratio, 'Splitting a body 8 times as long took over 32 times as long');
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function bodyProvider(): array
    {
        $long = str_repeat('a', times: Content::BLOCK + 100);

        return [
            'CRLF'                        => ["--b\r\none\r\n--b\r\ntwo\r\n--b--\r\n", ['one', 'two']],
            'LF'                          => ["--b\none\n--b\ntwo\n--b--\n", ['one', 'two']],
            'preamble and epilogue'       => ["pre\r\n--b\r\none\r\n--b--\r\npost\r\n--b\r\nx", ['one']],
            'no closing boundary'         => ["--b\r\none\r\n--b\r\ntwo", ['one', 'two']],
            'no boundary at all'          => ["one\r\ntwo", []],
            'empty part'                  => ["--b\r\n--b\r\nx\r\n--b--", ['', 'x']],
            'empty body'                  => ['', []],
            'boundary after blank line'   => ["\n--b\nx\n--b--", ['x']],
            'only starts with boundary'   => ["--b\r\n--bx\r\n --b\r\n--b--x\r\n--b--", ["--bx\r\n --b\r\n--b--x"]],
            'transport padding'           => ["--b \t\r\none\r\n--b-- \r\n", ['one']],
            'boundary inside a long line' => [
                "--b\r\n" . str_repeat('a', times: Content::CHUNK) . "--b\r\n--b--",
                [
                    str_repeat('a', times: Content::CHUNK) . '--b',
                ],
            ],
            'part longer than a block'    => ["--b\r\n{$long}\r\n--b\r\nx\r\n--b--", [$long, 'x']],
            'CR alone before boundary'    => ["--b\r\none\r\r\n--b--", ["one\r"]],
            'boundary at the very end'    => ["--b\r\none\r\n--b", ['one', '']],
            'carriage return then LF'     => ["--b\none\r\n--b\ntwo\n--b--", ['one', 'two']],
        ];
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function blockEdgeProvider(): array
    {
        $cases = [];
        foreach (["\r\n", "\n"] as $lineBreak) {
            for ($fill = Content::BLOCK - 12; $fill <= (Content::BLOCK + 2); $fill++) {
                $cases[strlen($lineBreak) . " byte break, {$fill} bytes"] = [$fill, $lineBreak];
            }
        }

        return $cases;
    }

    /**
     * @return list<string>
     */
    private static function parts(Content $body): array
    {
        return array_map(static fn(Content $part): string => $part->read(), MultipartSplitter::split($body, 'b'));
    }
}
