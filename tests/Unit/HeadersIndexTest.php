<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Closure;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Header\HeaderInterface;
use Contenir\Mail\Headers;
use Contenir\Mail\Tests\TestAsset\CountingHeader;
use Contenir\Mail\Tests\TestAsset\Growth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function range;
use function serialize;
use function unserialize;

/**
 * Headers keeps the position of each name, worked out once, so lookups and
 * additions do not go over every header; these tests hold every way of making
 * or changing headers to the same answers.
 */
#[CoversClass(Headers::class)]
#[Group('unit')]
final class HeadersIndexTest extends TestCase
{
    /**
     * @return array<string, array{Closure(): Headers}>
     */
    public static function sameHeadersProvider(): array
    {
        return [
            'constructor'   => [
                static fn(): Headers => new Headers(
                    self::header('A', '1'),
                    self::header('X-Tag', 'one'),
                    self::header('B', '2'),
                    self::header('x_tag', 'two'),
                ),
            ],
            'fromString'    => [
                static fn(): Headers => Headers::fromString("A: 1\r\nX-Tag: one\r\nB: 2\r\nx_tag: two\r\n"),
            ],
            'withAdded'     => [
                static fn(): Headers => (new Headers())->withAdded(self::header('A', '1'))
                    ->withAdded(self::header('X-Tag', 'one'))
                    ->withAdded(self::header('B', '2'))
                    ->withAdded(self::header('x_tag', 'two')),
            ],
            'withFirst'     => [
                static fn(): Headers => (new Headers())->withFirst(self::header('x_tag', 'two'))
                    ->withFirst(self::header('B', '2'))
                    ->withFirst(self::header('X-Tag', 'one'))
                    ->withFirst(self::header('A', '1')),
            ],
            'with replaced' => [
                static fn(): Headers => (new Headers(
                    self::header('A', '0'),
                    self::header('X-Tag', 'one'),
                    self::header('B', '2'),
                    self::header('x_tag', 'two'),
                ))->with(self::header('A', '1')),
            ],
            'without'       => [
                static fn(): Headers => (new Headers(
                    self::header('C', '3'),
                    self::header('A', '1'),
                    self::header('X-Tag', 'one'),
                    self::header('c', '4'),
                    self::header('B', '2'),
                    self::header('x_tag', 'two'),
                ))->without('C'),
            ],
            'unserialized'  => [
                static fn(): Headers => self::unserialized(
                    new Headers(
                        self::header('A', '1'),
                        self::header('X-Tag', 'one'),
                        self::header('B', '2'),
                        self::header('x_tag', 'two'),
                    ),
                ),
            ],
        ];
    }

    /**
     * @param Closure(): Headers $make
     */
    #[DataProvider('sameHeadersProvider')]
    #[Test]
    public function findsEveryHeaderOfANameWhicheverWayTheHeadersWereMade(Closure $make): void
    {
        static::assertSame(['one', 'two'], self::values($make()->all('x.TAG')));
    }

    /**
     * @param Closure(): Headers $make
     */
    #[DataProvider('sameHeadersProvider')]
    #[Test]
    public function findsTheFirstHeaderOfANameWhicheverWayTheHeadersWereMade(Closure $make): void
    {
        static::assertSame('one', $make()->get('x tag')?->getFieldValue());
    }

    /**
     * @param Closure(): Headers $make
     */
    #[DataProvider('sameHeadersProvider')]
    #[Test]
    public function findsHeadersAfterTheOnesOfARepeatedNameWhicheverWayTheHeadersWereMade(Closure $make): void
    {
        static::assertSame('2', $make()->get('b')?->getFieldValue());
    }

    /**
     * @param Closure(): Headers $make
     */
    #[DataProvider('sameHeadersProvider')]
    #[Test]
    public function keepsTheOrderWhicheverWayTheHeadersWereMade(Closure $make): void
    {
        static::assertSame(['1', 'one', '2', 'two'], self::values($make()->toList()));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function hasProvider(): array
    {
        return [
            'same spelling'      => ['X-Tag', true],
            'other case'         => ['X-TAG', true],
            'underscore'         => ['x_tag', true],
            'dot and space'      => ['x.t ag', true],
            'other name'         => ['X-Other', false],
            'prefix of the name' => ['X', false],
        ];
    }

    #[DataProvider('hasProvider')]
    #[Test]
    public function tellsWhetherItHoldsAHeaderOfAName(string $name, bool $expected): void
    {
        static::assertSame($expected, (new Headers(self::header('X-Tag', 'one')))->has($name));
    }

    #[Test]
    public function findsNothingForANameItDoesNotHold(): void
    {
        static::assertNull((new Headers(self::header('X-Tag', 'one')))->get('X-Other'));
    }

    #[Test]
    public function setsAHeaderOfANewNameLast(): void
    {
        $headers = (new Headers(self::header('A', '1')))->with(self::header('B', '2'));

        static::assertSame(['1', '2'], self::values($headers->toList()));
    }

    #[Test]
    public function findsAHeaderAddedAfterOthersOfTheSameName(): void
    {
        $headers = (new Headers(self::header('Received', 'one')))->withAdded(self::header('received', 'two'));

        static::assertSame(['one', 'two'], self::values($headers->all('RECEIVED')));
    }

    #[Test]
    public function findsAHeaderAddedFirstBeforeOthersOfTheSameName(): void
    {
        $headers = (new Headers(self::header('A', '1'), self::header('Received', 'one')))->withFirst(self::header(
            'Received',
            'zero',
        ));

        static::assertSame(['zero', 'one'], self::values($headers->all('Received')));
    }

    #[Test]
    public function setsAHeaderInPlaceOfTheFirstOfSeveralAndDropsTheRest(): void
    {
        $headers = new Headers(
            self::header('A', '1'),
            self::header('X-Tag', 'one'),
            self::header('B', '2'),
            self::header('X-Tag', 'two'),
            self::header('C', '3'),
        );

        static::assertSame(
            ['1', 'new', '2', '3'],
            self::values($headers->with(self::header('x-tag', 'new'))->toList()),
        );
    }

    #[Test]
    public function findsHeadersAfterTheDroppedOnesOnceSeveralAreReplaced(): void
    {
        $headers = new Headers(
            self::header('X-Tag', 'one'),
            self::header('X-Tag', 'two'),
            self::header('B', '2'),
        );

        static::assertSame('2', $headers->with(self::header('X-Tag', 'new'))->get('B')?->getFieldValue());
    }

    #[Test]
    public function findsTheHeaderSetInPlaceOfSeveral(): void
    {
        $headers = new Headers(self::header('X-Tag', 'one'), self::header('B', '2'), self::header('X-Tag', 'two'));

        static::assertSame(['new'], self::values($headers->with(self::header('X-Tag', 'new'))->all('X-Tag')));
    }

    #[Test]
    public function findsHeadersOfANameAddedAfterOneWasSetInPlace(): void
    {
        $headers = (new Headers(self::header('A', '1'), self::header('B', '2')))->with(self::header('A', 'new'))
            ->withAdded(self::header('A', 'more'));

        static::assertSame(['new', 'more'], self::values($headers->all('A')));
    }

    #[Test]
    public function findsNothingOfARemovedName(): void
    {
        $headers = (new Headers(self::header('A', '1'), self::header('B', '2'), self::header('A', '3')))->without('a');

        static::assertSame([[], '2'], [$headers->all('A'), $headers->get('B')?->getFieldValue()]);
    }

    #[Test]
    public function leavesTheHeadersItWasMadeFromAsTheyWere(): void
    {
        $original = new Headers(self::header('A', '1'), self::header('B', '2'), self::header('A', '3'));
        $changes  = [
            $original->with(self::header('A', 'new')),
            $original->with(self::header('C', 'new')),
            $original->withAdded(self::header('A', 'more')),
            $original->withFirst(self::header('B', 'first')),
            $original->without('A'),
        ];

        static::assertSame(
            [['1', '3'], '2', null, 5],
            [
                self::values($original->all('A')),
                $original->get('B')?->getFieldValue(),
                $original->get('C'),
                count($changes),
            ],
        );
    }

    #[Test]
    public function keepsTheWrittenTextOfAParsedHeaderSetAgain(): void
    {
        $headers = Headers::fromString("Subject:   Hello\r\nX-A: 1\r\nX-A: 2\r\n");
        $subject = $headers->get('Subject');
        static::assertNotNull($subject);

        static::assertSame("Subject:   Hello\r\nX-A: 1\r\nX-A: 2\r\n", $headers->with($subject)->toString());
    }

    #[Test]
    public function keepsTheWrittenTextOfTheOtherHeadersWhenOneIsReplaced(): void
    {
        $headers = Headers::fromString("Subject:   Hello\r\nX-A:  1\r\nX-A: 2\r\n");

        static::assertSame("Subject:   Hello\r\nX-A: new\r\n", $headers->with(self::header('X-A', 'new'))->toString());
    }

    #[Test]
    public function keepsTheWrittenTextOfTheHeadersAnAddedOneFollows(): void
    {
        $headers = Headers::fromString("Subject:   Hello\r\n");

        static::assertSame("Subject:   Hello\r\nX-A: 1\r\n", $headers->withAdded(self::header('X-A', '1'))->toString());
    }

    #[Test]
    public function keepsTheWrittenTextOfTheHeadersOneIsAddedBefore(): void
    {
        $headers = Headers::fromString("Subject:   Hello\r\n");

        static::assertSame("X-A: 1\r\nSubject:   Hello\r\n", $headers->withFirst(self::header('X-A', '1'))->toString());
    }

    #[Test]
    public function keepsTheWrittenTextOfTheHeadersItWasMadeFrom(): void
    {
        $headers = Headers::fromString("Subject:   Hello\r\nX-A:  1\r\n");
        $changes = [$headers->with(self::header('Subject', 'Bye')), $headers->without('X-A')];

        static::assertSame([2, "Subject:   Hello\r\nX-A:  1\r\n"], [count($changes), $headers->toString()]);
    }

    /**
     * @return array<string, array{Closure(Headers, HeaderInterface): Headers}>
     */
    public static function removedThenAddedBackProvider(): array
    {
        return [
            'removed'              => [
                static fn(Headers $headers, HeaderInterface $subject): Headers => $headers->without('Subject')
                    ->withAdded($subject),
            ],
            'replaced'             => [
                static fn(Headers $headers, HeaderInterface $subject): Headers => $headers->with(self::header(
                    'Subject',
                    'Bye',
                ))
                    ->with($subject),
            ],
            'replaced with others' => [
                static fn(Headers $headers, HeaderInterface $subject): Headers => $headers->withAdded(self::header(
                    'Subject',
                    'Bye',
                ))
                    ->with(self::header('Subject', 'Again'))
                    ->with($subject),
            ],
        ];
    }

    /**
     * A header that leaves the collection loses its written text, as it would
     * if the collection were built again from its headers.
     *
     * @param Closure(Headers, HeaderInterface): Headers $removeAndAddBack
     */
    #[DataProvider('removedThenAddedBackProvider')]
    #[Test]
    public function writesAParsedHeaderFromItsValueOnceRemovedAndAddedBack(Closure $removeAndAddBack): void
    {
        $headers = Headers::fromString("Subject:   Hello\r\n");
        $subject = $headers->get('Subject');
        static::assertNotNull($subject);

        static::assertSame("Subject: Hello\r\n", $removeAndAddBack($headers, $subject)->toString());
    }

    /**
     * Each lookup used to normalise the name of every header, so reading every
     * header of a large set took time growing with the square of its size.
     */
    #[Group('slow')]
    #[Test]
    public function looksUpHeadersInTimeThatDoesNotGrowWithTheirNumber(): void
    {
        $ratio = Growth::ratio(
            static fn(int $count): Headers => new Headers(...array_map(
                static fn(int $i): HeaderInterface => self::header("X-H-{$i}", 'v'),
                range(1, $count),
            )),
            static function (Headers $headers): void {
                foreach (range(1, count($headers)) as $i) {
                    $headers->get("x_h_{$i}");
                }
            },
            size: 150,
            factor: 8,
        );

        static::assertLessThan(32, $ratio, 'Looking up 8 times as many headers took over 32 times as long');
    }

    /**
     * Changing or reading headers used to normalise the name of every header
     * held; only building a collection anew goes over them now.
     *
     * @return array<string, array{Closure(Headers): mixed}>
     */
    public static function changeOrLookupProvider(): array
    {
        return [
            'adding'               => [
                static fn(Headers $headers): Headers => $headers->withAdded(self::header('B', 'more')),
            ],
            'setting a new name'   => [
                static fn(Headers $headers): Headers => $headers->with(self::header('D', 'new')),
            ],
            'setting a held name'  => [
                static fn(Headers $headers): Headers => $headers->with(self::header('B', 'new')),
            ],
            'adding after setting' => [
                static fn(Headers $headers): Headers => $headers->with(self::header('B', 'new'))
                    ->withAdded(self::header('E', 'more')),
            ],
            'getting'              => [static fn(Headers $headers): ?HeaderInterface => $headers->get('C')],
            'getting every one'    => [static fn(Headers $headers): array => $headers->all('B')],
            'asking'               => [static fn(Headers $headers): bool => $headers->has('A')],
        ];
    }

    /**
     * @param Closure(Headers): mixed $change
     */
    #[DataProvider('changeOrLookupProvider')]
    #[Test]
    public function doesNotReadTheNamesOfTheHeadersItHoldsAgain(Closure $change): void
    {
        $held    = [new CountingHeader('A'), new CountingHeader('B'), new CountingHeader('C')];
        $headers = new Headers(...$held);
        $change($headers);

        static::assertSame([1, 1, 1], array_map(static fn(CountingHeader $header): int => $header->nameReads(), $held));
    }

    private static function header(string $name, string $value): GenericHeader
    {
        return new GenericHeader($name, $value);
    }

    /**
     * @param list<HeaderInterface> $headers
     * @return list<string>
     */
    private static function values(array $headers): array
    {
        return array_map(static fn(HeaderInterface $header): string => $header->getFieldValue(), $headers);
    }

    private static function unserialized(Headers $headers): Headers
    {
        $copy = unserialize(serialize($headers));
        static::assertInstanceOf(Headers::class, $copy);

        return $copy;
    }
}
