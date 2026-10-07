<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use ArrayIterator;
use Contenir\Mail\Storage\ParamsNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ParamsNormalizerTest extends TestCase
{
    /** @psalm-return iterable<string, array{0: mixed}> */
    public static function invalidParams(): iterable
    {
        yield 'null' => [null];
        yield 'bool' => [true];
        yield 'int' => [1];
        yield 'float' => [1.1];
        yield 'string' => ['string'];
        yield 'list' => [[1, 2, 3]];
    }

    #[Test]
    #[DataProvider('invalidParams')]
    public function raisesErrorOnInvalidParamsTypes(mixed $params): void
    {
        $this->expectException(InvalidArgumentException::class);
        ParamsNormalizer::normalizeParams($params);
    }

    #[Test]
    public function returnsArrayMapVerbatim(): void
    {
        $params = [
            'foo'   => 'bar',
            'baz'   => [
                'this' => 'that',
            ],
            'some'  => 1,
            'thing' => 1.1,
            'else'  => null,
            'here'  => (object) ['foo' => 'bar'],
        ];

        static::assertSame($params, ParamsNormalizer::normalizeParams($params));
    }

    #[Test]
    public function convertsIterableMapToArrayMap(): void
    {
        $paramsArray = [
            'foo'   => 'bar',
            'baz'   => [
                'this' => 'that',
            ],
            'some'  => 1,
            'thing' => 1.1,
            'else'  => null,
            'here'  => (object) ['foo' => 'bar'],
        ];
        $params = new ArrayIterator($paramsArray);

        static::assertSame($paramsArray, ParamsNormalizer::normalizeParams($params));
    }

    #[Test]
    public function convertsObjectToArrayMap(): void
    {
        $paramsArray = [
            'foo'   => 'bar',
            'baz'   => [
                'this' => 'that',
            ],
            'some'  => 1,
            'thing' => 1.1,
            'else'  => null,
            'here'  => (object) ['foo' => 'bar'],
        ];
        $params = (object) $paramsArray;

        static::assertSame($paramsArray, ParamsNormalizer::normalizeParams($params));
    }
}
