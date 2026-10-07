<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Utf8;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function array_map;
use function count;
use function extension_loaded;
use function implode;
use function mb_check_encoding;
use function mb_scrub;
use function mb_str_split;
use function mb_strcut;
use function mb_strlen;
use function mb_substitute_character;
use function range;
use function sprintf;
use function strlen;

#[CoversClass(Utf8::class)]
#[Group('unit')]
final class Utf8Test extends TestCase
{
    /** Pieces random strings are built from: characters of each width, and the ill-formed sequences around them */
    private const array PIECES = [
        'a',
        "\x00",
        "\x7F",
        "\xC3\xA9",
        "\xDF\xBF",
        "\xE2\x82\xAC",
        "\xEF\xBF\xBF",
        "\xF0\x9F\x98\x80",
        "\xF4\x8F\xBF\xBF",
        "\x80",
        "\xBF",
        "\xC0",
        "\xC1",
        "\xC2",
        "\xDF",
        "\xE0",
        "\xE0\x9F",
        "\xE0\xA0",
        "\xED",
        "\xED\xA0",
        "\xED\x9F",
        "\xEF",
        "\xEF\xBF",
        "\xF0",
        "\xF0\x8F",
        "\xF0\x90",
        "\xF0\x9F",
        "\xF0\x9F\x98",
        "\xF3\xBF\xBF",
        "\xF4",
        "\xF4\x90",
        "\xF5",
        "\xF8",
        "\xFF",
    ];

    private const int RANDOM_CASES = 250;

    /** @var int|string|null The mbstring substitute character to restore after the test */
    private int|string|null $substituteCharacter = null;

    protected function setUp(): void
    {
        if (extension_loaded('mbstring')) {
            $this->substituteCharacter = mb_substitute_character();
            mb_substitute_character(0xFFFD);
        }
    }

    protected function tearDown(): void
    {
        if (null !== $this->substituteCharacter) {
            mb_substitute_character($this->substituteCharacter);
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function validityProvider(): array
    {
        return [
            'empty string'              => ['', true],
            'ASCII'                     => ['Hello', true],
            'two-byte character'        => ["caf\u{E9}", true],
            'three-byte character'      => ["\u{20AC}", true],
            'four-byte character'       => ["\u{1F600}", true],
            'highest code point'        => ["\u{10FFFF}", true],
            'mixed widths'              => ["a\u{E9}\u{20AC}\u{1F600}", true],
            'NUL'                       => ["\0", true],
            'overlong two-byte slash'   => ["\xC0\xAF", false],
            'overlong three-byte slash' => ["\xE0\x80\xAF", false],
            'overlong four-byte slash'  => ["\xF0\x80\x80\xAF", false],
            'surrogate'                 => ["\xED\xA0\x80", false],
            'above U+10FFFF'            => ["\xF4\x90\x80\x80", false],
            'five-byte form'            => ["\xF8\x88\x80\x80\x80", false],
            'truncated two-byte'        => ["\xC3", false],
            'truncated three-byte'      => ["\xE2\x82", false],
            'truncated four-byte'       => ["\xF0\x9F\x98", false],
            'truncated before ASCII'    => ["\xE2\x82A", false],
            'lone continuation byte'    => ["\x80", false],
            'Latin-1 byte'              => ["caf\xE9", false],
        ];
    }

    #[Test]
    #[DataProvider('validityProvider')]
    public function recognisesWellFormedUtf8(string $value, bool $expected): void
    {
        static::assertSame($expected, Utf8::isValid($value));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function splitProvider(): array
    {
        return [
            'empty string'           => ['', []],
            'ASCII'                  => ['ab', ['a', 'b']],
            'each width'             => ["a\u{E9}\u{20AC}\u{1F600}", ['a', "\u{E9}", "\u{20AC}", "\u{1F600}"]],
            'overlong'               => ["\xC0\xAF", ["\xC0", "\xAF"]],
            'surrogate'              => ["\xED\xA0\x80", ["\xED", "\xA0", "\x80"]],
            'above U+10FFFF'         => ["\xF4\x90\x80\x80", ["\xF4", "\x90", "\x80", "\x80"]],
            'truncated three-byte'   => ["\xE2\x82A", ["\xE2\x82", 'A']],
            'truncated four-byte'    => ["\xF0\x9F\x98a", ["\xF0\x9F\x98", 'a']],
            'truncated after F1-F3'  => ["\xF3\xBF\xBF", ["\xF3\xBF\xBF"]],
            'truncated after F4'     => ["\xF4\x8F\xBF", ["\xF4\x8F\xBF"]],
            'truncated after E0'     => ["\xE0\xA0", ["\xE0\xA0"]],
            'truncated after ED'     => ["\xED\x9F", ["\xED\x9F"]],
            'lead byte without tail' => ["\xC3A", ["\xC3", 'A']],
            'lone continuation byte' => ["a\x80b", ['a', "\x80", 'b']],
            'five-byte form'         => ["\xF8\x88", ["\xF8", "\x88"]],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('splitProvider')]
    public function splitsIntoCharactersAndIllFormedSubparts(string $value, array $expected): void
    {
        static::assertSame($expected, Utf8::split($value));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function lengthProvider(): array
    {
        return [
            'empty string'           => ['', 0],
            'each width'             => ["a\u{E9}\u{20AC}\u{1F600}", 4],
            'surrogate'              => ["\xED\xA0\x80", 3],
            'truncated three-byte'   => ["\xE2\x82A", 2],
            'lone continuation byte' => ["\x80", 1],
        ];
    }

    #[Test]
    #[DataProvider('lengthProvider')]
    public function countsCodePoints(string $value, int $expected): void
    {
        static::assertSame($expected, Utf8::length($value));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function scrubProvider(): array
    {
        return [
            'empty string'           => ['', ''],
            'well-formed'            => ["a\u{E9}\u{20AC}\u{1F600}", "a\u{E9}\u{20AC}\u{1F600}"],
            'Latin-1 byte'           => ["caf\xE9", "caf\u{FFFD}"],
            'overlong'               => ["\xC0\xAF", "\u{FFFD}\u{FFFD}"],
            'surrogate'              => ["\xED\xA0\x80", "\u{FFFD}\u{FFFD}\u{FFFD}"],
            'above U+10FFFF'         => ["\xF4\x90\x80\x80", "\u{FFFD}\u{FFFD}\u{FFFD}\u{FFFD}"],
            'truncated three-byte'   => ["\xE2\x82A", "\u{FFFD}A"],
            'truncated four-byte'    => ["\xF0\x9F\x98", "\u{FFFD}"],
            'lone continuation byte' => ["a\x80b", "a\u{FFFD}b"],
        ];
    }

    #[Test]
    #[DataProvider('scrubProvider')]
    public function replacesEachIllFormedSubpartWithReplacementCharacter(string $value, string $expected): void
    {
        static::assertSame($expected, Utf8::scrub($value));
    }

    /**
     * @return array<string, array{string, int<0, max>, string}>
     */
    public static function cutProvider(): array
    {
        return [
            'empty string'                     => ['', 0, ''],
            'shorter than the limit'           => ['abc', 5, 'abc'],
            'exactly the limit'                => ['abc', 3, 'abc'],
            'zero bytes'                       => ['abc', 0, ''],
            'between ASCII characters'         => ['abc', 2, 'ab'],
            'before a two-byte character'      => ["a\u{E9}", 1, 'a'],
            'inside a two-byte character'      => ["a\u{E9}b", 2, 'a'],
            'after a two-byte character'       => ["a\u{E9}b", 3, "a\u{E9}"],
            'first byte of three-byte'         => ["a\u{20AC}", 2, 'a'],
            'second byte of three-byte'        => ["a\u{20AC}b", 3, 'a'],
            'after a three-byte character'     => ["a\u{20AC}b", 4, "a\u{20AC}"],
            'first byte of four-byte'          => ["a\u{1F600}", 2, 'a'],
            'second byte of four-byte'         => ["a\u{1F600}", 3, 'a'],
            'third byte of four-byte'          => ["a\u{1F600}b", 4, 'a'],
            'after a four-byte character'      => ["a\u{1F600}b", 5, "a\u{1F600}"],
            'inside the first character'       => ["\u{1F600}", 3, ''],
            'continuation bytes back to start' => ["\x80\x80\x80\x80\x80", 4, ''],
        ];
    }

    /**
     * @param int<0, max> $maxBytes
     */
    #[Test]
    #[DataProvider('cutProvider')]
    public function cutsWithoutSplittingACharacter(string $value, int $maxBytes, string $expected): void
    {
        static::assertSame($expected, Utf8::cut($value, $maxBytes));
    }

    /**
     * Fixed edge cases and seeded random strings, well-formed or not.
     *
     * @return array<string, array{string}>
     */
    public static function anyInputProvider(): array
    {
        $cases = [];
        foreach (self::validityProvider() as $label => [$value]) {
            $cases[$label] = [$value];
        }

        $randomizer = new Randomizer(new Mt19937(20_261_008));
        foreach (range(1, self::RANDOM_CASES) as $index) {
            $cases[sprintf('random bytes %d', $index)]  = [$randomizer->getBytes($randomizer->getInt(1, 12))];
            $cases[sprintf('random pieces %d', $index)] = [self::randomPieces($randomizer)];
        }

        return $cases;
    }

    /**
     * Seeded random well-formed strings of characters of every width.
     *
     * @return array<string, array{string}>
     */
    public static function wellFormedProvider(): array
    {
        $randomizer = new Randomizer(new Mt19937(20_261_009));
        $characters = ['a', "\x7F", "\u{E9}", "\u{7FF}", "\u{20AC}", "\u{FFFF}", "\u{1F600}", "\u{10FFFF}"];
        $cases      = [];
        foreach (range(1, self::RANDOM_CASES) as $index) {
            $value = '';
            foreach (range(1, $randomizer->getInt(1, 8)) as $_) {
                $value .= $characters[$randomizer->getInt(0, count($characters) - 1)];
            }

            $cases[sprintf('well-formed %d', $index)] = [$value];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('anyInputProvider')]
    #[RequiresPhpExtension('mbstring')]
    #[Group('mbstring')]
    public function validatesAsMbCheckEncodingDoes(string $value): void
    {
        static::assertSame(mb_check_encoding($value, encoding: 'UTF-8'), Utf8::isValid($value));
    }

    #[Test]
    #[DataProvider('anyInputProvider')]
    #[RequiresPhpExtension('mbstring')]
    #[Group('mbstring')]
    public function countsAsMbStrlenDoes(string $value): void
    {
        static::assertSame(mb_strlen($value, encoding: 'UTF-8'), Utf8::length($value));
    }

    #[Test]
    #[DataProvider('anyInputProvider')]
    #[RequiresPhpExtension('mbstring')]
    #[Group('mbstring')]
    public function scrubsAsMbScrubDoesWithReplacementCharacter(string $value): void
    {
        static::assertSame(mb_scrub($value, encoding: 'UTF-8'), Utf8::scrub($value));
    }

    #[Test]
    #[DataProvider('anyInputProvider')]
    public function splitJoinsBackIntoTheInput(string $value): void
    {
        static::assertSame($value, implode('', Utf8::split($value)));
    }

    #[Test]
    #[DataProvider('wellFormedProvider')]
    #[RequiresPhpExtension('mbstring')]
    #[Group('mbstring')]
    public function splitsWellFormedTextAsMbStrSplitDoes(string $value): void
    {
        static::assertSame(mb_str_split($value, length: 1, encoding: 'UTF-8'), Utf8::split($value));
    }

    #[Test]
    #[DataProvider('wellFormedProvider')]
    #[RequiresPhpExtension('mbstring')]
    #[Group('mbstring')]
    public function cutsWellFormedTextAsMbStrcutDoesAtEveryLength(string $value): void
    {
        $lengths = range(0, strlen($value) + 1);

        static::assertSame(
            array_map(static fn(int $length): string => mb_strcut(
                $value,
                start: 0,
                length: $length,
                encoding: 'UTF-8',
            ), $lengths),
            array_map(static fn(int $length): string => Utf8::cut($value, $length), $lengths),
        );
    }

    private static function randomPieces(Randomizer $randomizer): string
    {
        $value = '';
        foreach (range(0, $randomizer->getInt(0, 8)) as $_) {
            $value .= self::PIECES[$randomizer->getInt(0, count(self::PIECES) - 1)];
        }

        return $value;
    }
}
