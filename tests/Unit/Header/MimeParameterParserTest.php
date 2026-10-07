<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\EncodedWordDecoder;
use Contenir\Mail\Header\Exception\InvalidArgumentException;
use Contenir\Mail\Header\MimeParameterParser;
use Contenir\Mail\Header\MimeParameters;
use Contenir\Mail\Header\ParameterText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function implode;
use function range;
use function sprintf;
use function str_repeat;

#[CoversClass(MimeParameterParser::class)]
#[CoversClass(ParameterText::class)]
#[CoversClass(MimeParameters::class)]
#[CoversClass(EncodedWordDecoder::class)]
#[Group('unit')]
final class MimeParameterParserTest extends TestCase
{
    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('parameterProvider')]
    #[Test]
    public function readsParameters(string $value, array $expected): void
    {
        static::assertSame($expected, MimeParameterParser::parse($value, headerLine: '', fieldName: 'X')[1]);
    }

    #[DataProvider('leadingValueProvider')]
    #[Test]
    public function readsLeadingValue(string $value, string $expected): void
    {
        static::assertSame($expected, MimeParameterParser::parse($value, headerLine: '', fieldName: 'X')[0]);
    }

    /**
     * Resource exhaustion: a parameter split into more sections than any real one needs is rejected.
     */
    #[Test]
    public function rejectsMoreSectionsThanTheLimit(): void
    {
        $sections = [];
        foreach (range(0, MimeParameterParser::MAX_SECTIONS) as $index) {
            $sections[] = sprintf('name*%d=x', $index);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for X string - more than 100 continuation sections');

        MimeParameterParser::parse('a; ' . implode('; ', $sections), headerLine: '', fieldName: 'X');
    }

    #[Test]
    public function readsSectionsUpToTheLimit(): void
    {
        $sections = [];
        foreach (range(0, MimeParameterParser::MAX_SECTIONS - 1) as $index) {
            $sections[] = sprintf('name*%d=x', $index);
        }

        static::assertSame(
            ['name' => str_repeat('x', times: MimeParameterParser::MAX_SECTIONS)],
            MimeParameterParser::parse('a; ' . implode('; ', $sections), headerLine: '', fieldName: 'X')[1],
        );
    }

    /**
     * Resource exhaustion: a huge section number does not make the parser count up to it.
     */
    #[Test]
    public function rejectsHugeSectionNumberAsIncomplete(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for X string - incomplete continuation; HeaderLine: line');

        MimeParameterParser::parse('a; name*99999999999=x', headerLine: 'line', fieldName: 'X');
    }

    #[DataProvider('segmentProvider')]
    #[Test]
    public function readsBackWhatItWrites(string $value): void
    {
        $written = 'a; ' . implode('; ', MimeParameters::segments('name', $value));

        static::assertSame(['name' => $value], MimeParameterParser::parse($written, headerLine: '', fieldName: 'X')[1]);
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function parameterProvider(): array
    {
        return [
            'token'                          => ['a; name=value', ['name' => 'value']],
            'quoted'                         => ['a; name="two words"', ['name' => 'two words']],
            'quoted pair'                    => ['a; name="say \"hi\" \\\\ ok"', ['name' => 'say "hi" \ ok']],
            'backslash at the very end'      => ['a; name="x\\', ['name' => 'x\\']],
            'unterminated quote'             => ['a; name="x; other=y', ['name' => 'x; other=y']],
            'spaces around equals'           => ['a; name = value ; other = 2', ['name' => 'value', 'other' => '2']],
            'junk after the value'           => ['a; name="x" junk; other=y', ['name' => 'x', 'other' => 'y']],
            'parameter without equals'       => ['a; flag; name=x', ['name' => 'x']],
            'empty name'                     => ['a; =x; name=y', ['name' => 'y']],
            'empty parameter'                => ['a;; name=y', ['name' => 'y']],
            'name case'                      => ['a; NaMe=x', ['name' => 'x']],
            'later replaces earlier'         => ['a; name=x; name=y', ['name' => 'y']],
            'folded'                         => ["a;\r\n name=x;\r\n\tother=y", ['name' => 'x', 'other' => 'y']],
            'encoded word in quotes'         => ['a; name="=?UTF-8?Q?caf=C3=A9?="', ['name' => 'café']],
            'encoded word unquoted'          => ['a; name==?UTF-8?B?Y2Fmw6k=?=', ['name' => 'café']],
            'tab becomes space'              => ["a; name=\"x\ty\"", ['name' => 'x y']],
            'control character removed'      => ['a; name="=?UTF-8?Q?x=00=0D=0Ay?="', ['name' => 'xy']],
            'invalid UTF-8 replaced'         => ['a; name="=?ISO-8859-1?Q?caf=E9?="', ['name' => 'café']],
            'extended ISO-8859-1'            => ["a; name*=iso-8859-1'en'caf%E9", ['name' => 'café']],
            'extended without charset'       => ["a; name*=''caf%C3%A9", ['name' => 'café']],
            'extended without quotes'        => ['a; name*=caf%C3%A9', ['name' => 'café']],
            'extended unknown charset'       => ["a; name*=x-unknown''abc", ['name' => 'abc']],
            'continued plain sections'       => ['a; name*0="ab"; name*1="cd"', ['name' => 'abcd']],
            'percent in plain section'       => ['a; name*0="100%25"', ['name' => '100%25']],
            'apostrophes in plain section'   => ["a; name*0=\"x'y'z\"", ['name' => "x'y'z"]],
            'continued encoded words'        => [
                'a; name*0="=?UTF-8?Q?a?="; name*1="=?UTF-8?Q?=C3=A9?="',
                ['name' => 'aé'],
            ],
            'mixed extended and plain'       => ["a; name*0*=UTF-8''%C3%A9; name*1=x", ['name' => 'éx']],
            'charset only in first section'  => ["a; name*0*=UTF-8''a; name*1*=x'y'z", ['name' => "ax'y'z"]],
            'order of first appearance'      => ['a; b*1=2; c=3; b*0=1', ['b' => '12', 'c' => '3']],
            'plain and continued name clash' => ['a; name=x; name*0=y', ['name' => 'y']],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function leadingValueProvider(): array
    {
        return [
            'alone'           => ['text/plain', 'text/plain'],
            'with parameters' => [' text/plain ; charset=x', 'text/plain'],
            'empty'           => ['', ''],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function segmentProvider(): array
    {
        return [
            'short ASCII'        => ['report.pdf'],
            'quotes and slashes' => ['a"b\\c'],
            'long ASCII'         => [str_repeat('abcdefghij', times: 20)],
            'short UTF-8'        => ['café.txt'],
            'long UTF-8'         => [str_repeat('é', times: 60)],
            'four-byte UTF-8'    => [str_repeat('😀', times: 30)],
            'percent and star'   => ["100% *é*'"],
            'empty'              => [''],
        ];
    }
}
