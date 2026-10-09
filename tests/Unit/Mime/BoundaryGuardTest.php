<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Mime\BoundaryGuard;
use Contenir\Mail\Mime\Exception\RuntimeException;
use Contenir\Mail\Tests\Unit\TestAsset\Growth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoundaryGuard::class)]
#[Group('unit')]
final class BoundaryGuardTest extends TestCase
{
    /**
     * @param list<string> $pieces
     */
    #[DataProvider('delimiterProvider')]
    #[Test]
    public function refusesLineStartingWithTheDelimiter(array $pieces): void
    {
        $guard = new BoundaryGuard('frontier');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'A part contains a line starting with its multipart boundary "frontier"; choose another boundary',
        );

        foreach ($pieces as $piece) {
            $guard->check($piece);
        }
    }

    /**
     * @param list<string> $pieces
     */
    #[DataProvider('safeProvider')]
    #[Test]
    public function acceptsDelimiterThatDoesNotStartALine(array $pieces): void
    {
        $guard = new BoundaryGuard('frontier');
        foreach ($pieces as $piece) {
            $guard->check($piece);
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Only the end of the text so far is kept between pieces, so a long part takes linear time.
     */
    #[Test]
    public function checksManyPiecesInLinearTime(): void
    {
        $ratio = Growth::ratio(
            static fn(int $pieces): int => $pieces,
            static function (int $pieces): void {
                $guard = new BoundaryGuard('frontier');
                for ($i = 0; $i < $pieces; $i++) {
                    $guard->check('x');
                }
            },
            5_000,
            factor: 8,
        );

        static::assertLessThan(24, $ratio);
    }

    #[Test]
    public function readsTheBoundaryLiterally(): void
    {
        $guard = new BoundaryGuard('a.b');
        $guard->check("--axb\r\n");

        $this->expectException(RuntimeException::class);

        $guard->check('--a.b');
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function delimiterProvider(): array
    {
        return [
            'at the start'                   => [['--frontier']],
            'after a line feed'              => [["text\r\n--frontier"]],
            'closing delimiter'              => [["text\r\n--frontier--"]],
            'longer line'                    => [["text\n--frontier and more"]],
            'at the start of a later piece'  => [["text\r\n", '--frontier']],
            'split after the dashes'         => [["text\r\n--", 'frontier']],
            'split inside the boundary'      => [["text\r\n--fron", 'tier']],
            'split into three pieces'        => [["text\r\n--fr", 'on', 'tier']],
            'split before the line feed'     => [['text', "\n--frontier"]],
            'after an empty piece'           => [["text\r\n", '', '--frontier']],
            'after a piece that ends a line' => [['--fron', "tier text\r\n", '--frontier']],
            'after a short line'             => [["--fron\r\n", '--frontier']],
            'after a line as long'           => [["0123456789\r\n", '--frontier']],
            'start split after a long line'  => [["0123456789\r\n--fron", 'tier']],
        ];
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function safeProvider(): array
    {
        return [
            'inside a line'                   => [['text --frontier']],
            'other boundary'                  => [["text\r\n--frontline"]],
            'single dash'                     => [["text\r\n-frontier"]],
            'inside a line split'             => [['text --fron', 'tier']],
            'piece that continues a line'     => [['text', ' --frontier']],
            'line longer than the delimiter'  => [['0123456789', '--frontier']],
            'line that reaches its length'    => [['0123456', '789', '--frontier']],
            'delimiter prefix then more text' => [["\n--fron", 'x', 'tier']],
        ];
    }
}
