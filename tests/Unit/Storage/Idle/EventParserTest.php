<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Idle;

use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\Idle\EventInterface;
use Contenir\Mail\Storage\Idle\EventParser;
use Contenir\Mail\Storage\Idle\Exists;
use Contenir\Mail\Storage\Idle\Expunge;
use Contenir\Mail\Storage\Idle\FlagsChanged;
use Contenir\Mail\Storage\Idle\Recent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventParser::class)]
#[CoversClass(Exists::class)]
#[CoversClass(Expunge::class)]
#[CoversClass(Recent::class)]
#[CoversClass(FlagsChanged::class)]
#[Group('unit')]
final class EventParserTest extends TestCase
{
    /**
     * @param array<mixed> $tokens
     */
    #[Test]
    #[DataProvider('eventProvider')]
    public function readsTheEventAResponseTellsOf(array $tokens, EventInterface $expected): void
    {
        static::assertEquals($expected, EventParser::fromResponse($tokens));
    }

    /**
     * @return array<string, array{array<mixed>, EventInterface}>
     */
    public static function eventProvider(): array
    {
        return [
            'new mail'              => [['3', 'EXISTS'], new Exists(3)],
            'an empty folder'       => [['0', 'EXISTS'], new Exists(0)],
            'a removed message'     => [['2', 'EXPUNGE'], new Expunge(2)],
            'recent messages'       => [['1', 'RECENT'], new Recent(1)],
            'a name in lower case'  => [['3', 'exists'], new Exists(3)],
            'changed flags'         => [
                ['4', 'FETCH', ['FLAGS', ['\Seen', '$Junk']]],
                new FlagsChanged(4, [Flag::Seen, '$Junk']),
            ],
            'flags after the UID'   => [
                ['4', 'FETCH', ['UID', '17', 'flags', ['\Flagged']]],
                new FlagsChanged(4, [Flag::Flagged]),
            ],
            'flags all removed'     => [['4', 'FETCH', ['FLAGS', []]], new FlagsChanged(4, [])],
            'a flag that is a list' => [['4', 'FETCH', ['FLAGS', [['\Seen']]]], new FlagsChanged(4, [''])],
        ];
    }

    /**
     * @param array<mixed> $tokens
     */
    #[Test]
    #[DataProvider('nothingProvider')]
    public function readsNoEventFromOtherResponses(array $tokens): void
    {
        static::assertNull(EventParser::fromResponse($tokens));
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function nothingProvider(): array
    {
        return [
            'a status response'      => [['OK', 'Still', 'here']],
            'no number'              => [['EXISTS']],
            'a number and no name'   => [['3']],
            'a negative number'      => [['-3', 'EXISTS']],
            'a number with a suffix' => [['3x', 'EXISTS']],
            'a number with a prefix' => [['x3', 'EXISTS']],
            'a list for a number'    => [[['3'], 'EXISTS']],
            'a list for a name'      => [['3', ['EXISTS']]],
            'an unknown name'        => [['3', 'ARRIVED']],
            'a fetch without flags'  => [['4', 'FETCH', ['UID', '17']]],
            'a fetch without items'  => [['4', 'FETCH']],
            'flags that are no list' => [['4', 'FETCH', ['FLAGS', '\Seen']]],
            'flags without a value'  => [['4', 'FETCH', ['FLAGS']]],
            'a list for an item'     => [['4', 'FETCH', [['FLAGS'], ['\Seen']]]],
        ];
    }
}
