<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Tnef;

use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\Tnef\ByteReader;
use Contenir\Mail\Storage\Tnef\MapiProperties;
use Contenir\Mail\Storage\Tnef\Properties;
use Contenir\Mail\Tests\Unit\TestAsset\TnefBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;
use function str_repeat;

#[CoversClass(MapiProperties::class)]
#[CoversClass(ByteReader::class)]
#[CoversClass(Properties::class)]
#[Group('unit')]
final class MapiPropertiesTest extends TestCase
{
    #[Test]
    public function readsNoPropertiesFromAnEmptyList(): void
    {
        static::assertSame([], self::read(TnefBuilder::properties()));
    }

    #[DataProvider('fixedTypeProvider')]
    #[Test]
    public function readsEachFixedLengthTypeByItsSize(int $type, int $size): void
    {
        $value = str_repeat("\x5A", $size);

        static::assertSame(
            [0x0001 => [$type, $value], 0x3707 => [TnefBuilder::TYPE_BINARY, 'after']],
            self::read(TnefBuilder::properties(
                TnefBuilder::property($type, 0x0001, $value),
                TnefBuilder::property(TnefBuilder::TYPE_BINARY, 0x3707, 'after'),
            )),
        );
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function fixedTypeProvider(): array
    {
        return [
            'PtypInteger16'    => [0x0002, 4],
            'PtypInteger32'    => [0x0003, 4],
            'PtypFloating32'   => [0x0004, 4],
            'PtypFloating64'   => [0x0005, 8],
            'PtypCurrency'     => [0x0006, 8],
            'PtypFloatingTime' => [0x0007, 8],
            'PtypErrorCode'    => [0x000A, 4],
            'PtypBoolean'      => [0x000B, 4],
            'PtypInteger64'    => [0x0014, 8],
            'PtypTime'         => [0x0040, 8],
            'PtypGuid'         => [0x0048, 16],
        ];
    }

    #[DataProvider('variableTypeProvider')]
    #[Test]
    public function readsEachVariableLengthTypeWithoutItsPadding(int $type): void
    {
        static::assertSame(
            [0x0001 => [$type, 'abcde'], 0x0002 => [0x0003, 'last']],
            self::read(TnefBuilder::properties(
                TnefBuilder::property($type, 0x0001, 'abcde'),
                TnefBuilder::property(0x0003, 0x0002, 'last'),
            )),
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function variableTypeProvider(): array
    {
        return [
            'PtypObject'  => [0x000D],
            'PtypString8' => [0x001E],
            'PtypString'  => [0x001F],
            'PtypBinary'  => [0x0102],
        ];
    }

    #[Test]
    public function readsAValueWhoseLengthIsAMultipleOfFour(): void
    {
        static::assertSame(
            [0x0001 => [TnefBuilder::TYPE_BINARY, 'abcd'], 0x0002 => [0x0003, 'last']],
            self::read(TnefBuilder::properties(
                TnefBuilder::property(TnefBuilder::TYPE_BINARY, 0x0001, 'abcd'),
                TnefBuilder::property(0x0003, 0x0002, 'last'),
            )),
        );
    }

    #[Test]
    public function keepsTheFirstValueOfAVariableLengthProperty(): void
    {
        $property =
            pack('vvV', TnefBuilder::TYPE_BINARY, 0x0001, 2) . pack('V', 3) . "one\0" . pack('V', 3) . 'two' . "\0";

        static::assertSame(
            [0x0001 => [TnefBuilder::TYPE_BINARY, 'one']],
            self::read(TnefBuilder::properties($property)),
        );
    }

    #[Test]
    public function readsAVariableLengthPropertyWithNoValues(): void
    {
        static::assertSame(
            [0x0002 => [0x0003, 'last']],
            self::read(TnefBuilder::properties(
                pack('vvV', TnefBuilder::TYPE_BINARY, 0x0001, 0),
                TnefBuilder::property(0x0003, 0x0002, 'last'),
            )),
        );
    }

    #[DataProvider('multiValuedProvider')]
    #[Test]
    public function skipsMultiValuedProperties(int $type, string $value): void
    {
        static::assertSame(
            [0x0002 => [0x0003, 'last']],
            self::read(TnefBuilder::properties(
                TnefBuilder::multiValued($type, 0x0001, [$value, $value]),
                TnefBuilder::property(0x0003, 0x0002, 'last'),
            )),
        );
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function multiValuedProvider(): array
    {
        return [
            'fixed length'    => [0x0014, '12345678'],
            'variable length' => [TnefBuilder::TYPE_BINARY, 'abc'],
        ];
    }

    #[DataProvider('namedPropertyProvider')]
    #[Test]
    public function skipsNamedProperties(int|string $name): void
    {
        static::assertSame(
            [0x0002 => [0x0003, 'last']],
            self::read(TnefBuilder::properties(
                TnefBuilder::namedProperty(TnefBuilder::TYPE_BINARY, 0x8000, $name, 'abc'),
                TnefBuilder::property(0x0003, 0x0002, 'last'),
            )),
        );
    }

    /**
     * @return array<string, array{int|string}>
     */
    public static function namedPropertyProvider(): array
    {
        return [
            'by number'                    => [0x8233],
            'by a name needing padding'    => ['Keywords'],
            'by a name needing no padding' => ['Odd'],
        ];
    }

    #[Test]
    public function keepsTheHighestNumericProperty(): void
    {
        static::assertSame(
            [0x7FFF => [0x0003, 'last']],
            self::read(TnefBuilder::properties(TnefBuilder::property(0x0003, 0x7FFF, 'last'))),
        );
    }

    #[DataProvider('malformedProvider')]
    #[Test]
    public function refusesMalformedProperties(string $data, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        self::read($data);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedProvider(): array
    {
        $named = pack('V', 1) . pack('vv', TnefBuilder::TYPE_BINARY, 0x8000) . str_repeat("\x11", times: 16);

        return [
            'no count'                         => [
                '',
                'The TNEF data ends early: 4 bytes are needed at offset 0, but 0 remain',
            ],
            'count larger than the data'       => [
                pack('V', 3) . str_repeat("\0", times: 11),
                'A MAPI count of 3 is more than the 11 bytes left can hold',
            ],
            'count of 4 billion'               => [
                pack('V', 0xFFFF_FFFF) . str_repeat("\0", times: 8),
                'A MAPI count of 4294967295 is more than the 8 bytes left can hold',
            ],
            'unknown type'                     => [
                TnefBuilder::properties(TnefBuilder::property(0x0999, 0x0001, 'abcd')),
                'A MAPI property has the unknown type 0x0999',
            ],
            'unknown multi-valued type'        => [
                TnefBuilder::properties(pack('vvV', 0x1999, 0x0001, 0)),
                'A MAPI property has the unknown type 0x0999',
            ],
            'fixed value cut short'            => [
                TnefBuilder::properties(pack('vv', 0x0014, 0x0001) . 'abcd'),
                'The TNEF data ends early: 8 bytes are needed at offset 8, but 4 remain',
            ],
            'value count larger than the data' => [
                TnefBuilder::properties(pack('vvV', TnefBuilder::TYPE_BINARY, 0x0001, 2) . pack('V', 0)),
                'A MAPI count of 2 is more than the 4 bytes left can hold',
            ],
            'value length past the end'        => [
                TnefBuilder::properties(pack('vvVV', TnefBuilder::TYPE_BINARY, 0x0001, 1, 0x7FFF_FFFF)),
                'The TNEF data ends early: 2147483647 bytes are needed at offset 16, but 0 remain',
            ],
            'missing padding'                  => [
                TnefBuilder::properties(pack('vvVV', TnefBuilder::TYPE_BINARY, 0x0001, 1, 3) . 'abc'),
                'The TNEF data ends early: 1 bytes are needed at offset 19, but 0 remain',
            ],
            'named property cut short'         => [
                pack('V', 1) . pack('vv', TnefBuilder::TYPE_BINARY, 0x8000) . str_repeat("\x11", times: 15),
                'The TNEF data ends early: 16 bytes are needed at offset 8, but 15 remain',
            ],
            'named property of unknown kind'   => [
                $named . pack('V', 2),
                'A MAPI named property has the unknown kind 2',
            ],
            'name length past the end'         => [
                $named . pack('VV', 1, 0x7FFF_FFFF),
                'The TNEF data ends early: 2147483648 bytes are needed at offset 32, but 0 remain',
            ],
        ];
    }

    /**
     * @return array<int, array{int, string}>
     */
    private static function read(string $data): array
    {
        return MapiProperties::read($data)->values;
    }
}
