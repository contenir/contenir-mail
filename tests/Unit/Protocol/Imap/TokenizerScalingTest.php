<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Closure;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ExposedImap;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use Contenir\Mail\Tests\Unit\TestAsset\Growth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function implode;
use function range;
use function str_repeat;

/**
 * The tokenizer reads a line in one pass: each token used to copy the rest of the line,
 * so a SEARCH reply of 200,000 ids took nine seconds to read.
 */
#[CoversClass(Imap::class)]
#[Group('unit')]
final class TokenizerScalingTest extends TestCase
{
    /**
     * @param list<mixed> $expected
     */
    #[DataProvider('tokenProvider')]
    #[Test]
    public function decodesTokensAroundListsNextToEachOther(string $response, array $expected): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->reply($response)->hangUp());

        $imap->readLine($tokens);

        static::assertSame($expected, $tokens);
    }

    /**
     * @return array<string, array{string, list<mixed>}>
     */
    public static function tokenProvider(): array
    {
        return [
            'lists side by side after a literal' => [
                "* X ({1}\r\na (b)(c))\r\n",
                ['X', ['a', ['b'], ['c']]],
            ],
            'lists side by side after a string'  => [
                "* X (\"a b\" (c)(d)) e\r\n",
                ['X', ['a b', ['c'], ['d']], 'e'],
            ],
            'string with list marks, then lists' => [
                "* X (\"a)(b\" (c)(d))\r\n",
                ['X', ['a)(b', ['c'], ['d']]],
            ],
            'three lists side by side'           => [
                "* X ((a)(b)(c))\r\n",
                ['X', [['a'], ['b'], ['c']]],
            ],
        ];
    }

    #[Test]
    public function decodesEveryIdOfALargeSearchReply(): void
    {
        $tokens = self::decoder()->decode(self::search(200_000));

        static::assertCount(200_001, $tokens);
    }

    /**
     * @param Closure(int): string $line
     */
    #[DataProvider('lineProvider')]
    #[Group('slow')]
    #[Test]
    public function decodesALongLineInLinearTime(Closure $line): void
    {
        $imap  = self::decoder();
        $ratio = Growth::ratio(
            $line,
            static fn(string $input): int => count($imap->decode($input)),
            size: 2_500,
            factor: 16,
        );

        static::assertLessThan(24, $ratio, 'Decoding a line 16 times as long took over 24 times as long');
    }

    /**
     * @return array<string, array{Closure(int): string}>
     */
    public static function lineProvider(): array
    {
        return [
            'SEARCH ids'                   => [self::search(...)],
            'quoted strings'               => [static fn(int $strings): string => str_repeat('"a b" ', $strings)],
            'lists side by side, no space' => [static fn(int $lists): string => '(' . str_repeat('(a)', $lists) . ')'],
        ];
    }

    private static function decoder(): ExposedImap
    {
        return new ExposedImap(connection: new InMemoryConnection());
    }

    private static function search(int $ids): string
    {
        return 'SEARCH ' . implode(' ', range(1_000_000, 1_000_000 + $ids - 1));
    }
}
