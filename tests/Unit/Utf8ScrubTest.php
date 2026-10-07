<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Utf8;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function ini_get;
use function ini_set;
use function str_repeat;

/**
 * Scrubbing works on slices of 65,536 bytes, ending each slice where a new
 * character or ill-formed unit must begin, so that input of any length stays
 * within PCRE's limits.
 */
#[CoversClass(Utf8::class)]
#[Group('unit')]
final class Utf8ScrubTest extends TestCase
{
    private const string REPLACEMENT = "\u{FFFD}";

    private string $backtrackLimit = '';

    #[Override]
    protected function setUp(): void
    {
        $this->backtrackLimit = (string) ini_get('pcre.backtrack_limit');
    }

    /**
     * @mago-expect lint:no-ini-set Restores the PCRE limit the fallback test lowers.
     */
    #[Override]
    protected function tearDown(): void
    {
        ini_set('pcre.backtrack_limit', $this->backtrackLimit);
    }

    #[Test]
    #[DataProvider('sliceBoundaryProvider')]
    public function scrubsAcrossSliceBoundaries(string $value, string $expected): void
    {
        static::assertSame($expected, Utf8::scrub($value));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sliceBoundaryProvider(): array
    {
        $before = str_repeat('a', times: 65_534);

        return [
            'character across the boundary'        => [
                "\xFF{$before}é\xFF",
                self::REPLACEMENT . "{$before}é" . self::REPLACEMENT,
            ],
            'ill-formed sequence across it'        => [
                "\xFF{$before}\xF0\x9F\x98A",
                self::REPLACEMENT . $before . self::REPLACEMENT . 'A',
            ],
            'four-byte character across it'        => [
                "\xFF{$before}😀\xFF",
                self::REPLACEMENT . "{$before}😀" . self::REPLACEMENT,
            ],
            'character before continuation bytes'  => [
                "\xFF" . str_repeat('a', times: 65_532) . "€\x80\x80\xFF",
                self::REPLACEMENT . str_repeat('a', times: 65_532) . '€' . str_repeat(self::REPLACEMENT, times: 3),
            ],
            'lone continuation bytes past it'      => [
                "\xFF" . str_repeat("\x80", times: 70_000),
                str_repeat(self::REPLACEMENT, times: 70_001),
            ],
            'ill-formed bytes over several slices' => [
                str_repeat("\xC3", times: 200_000),
                str_repeat(self::REPLACEMENT, times: 200_000),
            ],
        ];
    }

    #[Test]
    public function keepsWellFormedTextUnchanged(): void
    {
        $text = str_repeat('Grüße 😀 ', times: 20_000);

        static::assertSame($text, Utf8::scrub($text));
    }

    /**
     * When PCRE cannot match a run within its limit, scrubbing falls back
     * to one character at a time instead of losing the text.
     *
     * @mago-expect lint:no-ini-set Lowers the PCRE limit so a short run reaches it; tearDown() restores it.
     */
    #[Test]
    public function scrubsByCharacterWhenRunsReachThePcreLimit(): void
    {
        ini_set('pcre.backtrack_limit', value: '100');

        static::assertSame(
            'ok' . str_repeat(self::REPLACEMENT, times: 1_000) . 'é',
            Utf8::scrub('ok' . str_repeat("\xC3", times: 1_000) . 'é'),
        );
    }
}
