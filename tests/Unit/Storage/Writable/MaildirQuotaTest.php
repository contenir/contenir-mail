<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Writable;

use Contenir\Mail\Storage\Writable\MaildirQuota;
use Contenir\Mail\Tests\Trait\UsesTemporaryDirectoryTrait;
use Contenir\Mail\Tests\Unit\Storage\TestAsset\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function is_file;
use function mkdir;
use function symlink;

#[CoversClass(MaildirQuota::class)]
#[Group('unit')]
final class MaildirQuotaTest extends TestCase
{
    use UsesTemporaryDirectoryTrait;

    private string $root;

    private string $directory;

    protected function setUp(): void
    {
        $this->root = $this->setUpTemporaryDirectory();
        mkdir("{$this->root}/box");
        $this->directory = Fixtures::maildir("{$this->root}/box");
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    /**
     * Hostile maildirsize: malformed definitions and lines are ignored, and totals are clamped.
     */
    #[DataProvider('definitionProvider')]
    #[Test]
    public function parsesQuotaDefinition(string $line, array $expected): void
    {
        static::assertSame($expected, MaildirQuota::parseDefinition($line));
    }

    #[DataProvider('entriesProvider')]
    #[Test]
    public function sumsQuotaEntries(array $lines, array $expected): void
    {
        static::assertSame($expected, MaildirQuota::sumEntries($lines));
    }

    #[Test]
    public function writesQuotaDefinition(): void
    {
        static::assertSame('3000S,10C', MaildirQuota::definition(['count' => 10, 'size' => 3000]));
    }

    #[Test]
    public function writesEmptyQuotaDefinition(): void
    {
        static::assertSame('', MaildirQuota::definition([]));
    }

    #[Test]
    public function knowsWhenOverByCount(): void
    {
        static::assertTrue(MaildirQuota::isOver(['size' => 1, 'count' => 3, 'quota' => ['count' => 2]]));
    }

    #[Test]
    public function knowsWhenOverBySize(): void
    {
        static::assertTrue(MaildirQuota::isOver(['size' => 3, 'count' => 1, 'quota' => ['size' => 2]]));
    }

    #[Test]
    public function knowsWhenAtQuota(): void
    {
        static::assertFalse(MaildirQuota::isOver(['size' => 2, 'count' => 2, 'quota' => ['size' => 2, 'count' => 2]]));
    }

    #[Test]
    public function movesWrittenMaildirsizeIntoPlace(): void
    {
        file_put_contents("{$this->root}/written", data: 'new');
        MaildirQuota::replace("{$this->directory}/", "{$this->root}/written", [
            "{$this->directory}/cur" => filemtime("{$this->directory}/cur"),
        ]);

        static::assertSame('new', file_get_contents("{$this->directory}/maildirsize"));
    }

    #[Test]
    public function appendsToMaildirsize(): void
    {
        file_put_contents("{$this->root}/maildirsize", data: "1S\n");
        MaildirQuota::append("{$this->root}/", 5, 1);

        static::assertSame("1S\n5 1\n", file_get_contents("{$this->root}/maildirsize"));
    }

    #[Test]
    public function removesMaildirsizeWhenADirectoryChanged(): void
    {
        file_put_contents("{$this->root}/written", data: 'x');
        MaildirQuota::replace("{$this->directory}/", "{$this->root}/written", ["{$this->directory}/cur" => 1]);

        static::assertFalse(is_file("{$this->directory}/maildirsize"));
    }

    #[Test]
    public function countsSkippingWhatIsNotAMessage(): void
    {
        mkdir("{$this->root}/count");
        file_put_contents("{$this->root}/count/1,S=10:2,S", data: 'x');
        file_put_contents("{$this->root}/count/2", data: 'four');
        file_put_contents("{$this->root}/count/.hidden", data: 'x');
        symlink("{$this->root}/count/2", "{$this->root}/count/3");
        mkdir("{$this->root}/count/dir");

        static::assertSame([14, 2], [
            MaildirQuota::count(["{$this->root}/missing", "{$this->root}/count"])['size'],
            MaildirQuota::count(["{$this->root}/count"])['count'],
        ]);
    }

    #[Test]
    public function appendsNothingToMissingMaildirsize(): void
    {
        MaildirQuota::append("{$this->root}/", 1, 1);

        static::assertFalse(is_file("{$this->root}/maildirsize"));
    }

    #[Test]
    public function appendsNothingToLinkedMaildirsize(): void
    {
        file_put_contents("{$this->root}/target", data: 'x');
        symlink("{$this->root}/target", "{$this->root}/maildirsize");
        MaildirQuota::append("{$this->root}/", 1, 1);

        static::assertSame('x', file_get_contents("{$this->root}/target"));
    }

    /**
     * @return array<string, array{string, array<string, int>}>
     */
    public static function definitionProvider(): array
    {
        return [
            'size and count'  => ['1000S,10C', ['size' => 1000, 'count' => 10]],
            'spaces'          => [' 1000S , 10C ', ['size' => 1000, 'count' => 10]],
            'unknown field'   => ['10C,1L,3000S', ['count' => 10, 'size' => 3000]],
            'garbage'         => ['S,C,-5S,x10C,10Cx,10S\n', []],
            'empty'           => ['', []],
            'too many digits' => ['9999999999999999999S', []],
            'clamped'         => ['999999999999999999S', ['size' => 1_000_000_000_000_000]],
        ];
    }

    /**
     * @return array<string, array{list<string>, array{int, int}}>
     */
    public static function entriesProvider(): array
    {
        return [
            'lines'           => [['100 1', '-20 -1', ''], [80, 0]],
            'spaces'          => [['  5   2  '], [5, 2]],
            'malformed'       => [['x 1', '1', '1 2 3', '1.5 1'], [0, 0]],
            'malformed first' => [['x', '5 1'], [5, 1]],
            'clamped total'   => [
                ['999999999999999999 1', '999999999999999999 1'],
                [1_000_000_000_000_000,  2],
            ],
            'clamped below'   => [['-999999999999999999 -1', '-999999999999999999 0'], [-1_000_000_000_000_000, -1]],
        ];
    }
}
