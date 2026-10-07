<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\ListParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListParser::class)]
#[Group('unit')]
final class ListParserTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('listProvider')]
    #[Test]
    public function splitsOnDelimitersOutsideQuotes(string $value, array $expected): void
    {
        static::assertSame($expected, ListParser::parse($value));
    }

    #[Test]
    public function splitsOnTheGivenDelimiters(): void
    {
        static::assertSame(['a', 'b', 'c'], ListParser::parse('a=b;c', [';', '=']));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function listProvider(): array
    {
        return [
            'comma and semicolon'               => ['a,b;c', ['a', 'b', 'c']],
            'delimiter inside double quotes'    => ['"a,b",c', ['"a,b"', 'c']],
            'delimiter inside single quotes'    => ["'a,b',c", ["'a,b'", 'c']],
            'other quote inside quotes'         => ['"it\'s,ok",c', ['"it\'s,ok"', 'c']],
            'escaped delimiter'                 => ['a\\,b,c', ['a\\,b', 'c']],
            'escaped quote does not open quote' => ['a\\"b,c', ['a\\"b', 'c']],
            'trailing delimiter'                => ['a,', ['a']],
            'empty'                             => ['', []],
        ];
    }
}
