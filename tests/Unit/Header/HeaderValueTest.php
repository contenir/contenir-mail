<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\Exception;
use Contenir\Mail\Header\HeaderValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeaderValue::class)]
#[Group('unit')]
final class HeaderValueTest extends TestCase
{
    /**
     * Data for filter value
     */
    public static function getFilterValues(): array
    {
        return [
            ["This is a\n test",          'This is a test'],
            ["This is a\r test",          'This is a test'],
            ["This is a\n\r test",        'This is a test'],
            ["This is a\r\n  test",       "This is a\r\n  test"],
            ["This is a \r\ntest",        'This is a test'],
            ["This is a \r\n\n test",     'This is a  test'],
            ["This is a\n\n test",        'This is a test'],
            ["This is a\r\r test",        'This is a test'],
            ["This is a \r\r\n test",     "This is a \r\n test"],
            ["This is a \r\n\r\ntest",    'This is a test'],
            ["This is a \r\n\n\r\n test", "This is a \r\n test"],
            ["This is a test\r\n",        'This is a test'],
            ["a\x7Fb",                    'ab'],
            ["a\r\n ",                    "a\r\n "],
            ["a\rb",                      'ab'],
            ["a\x00\x1Bb\tc",             "ab\tc"],
            ["a\xC3\xA4b",                'ab'],
        ];
    }

    #[Test]
    #[DataProvider('getFilterValues')]
    #[Group('ZF2015-04')]
    public function filterValue(string $value, string $expected): void
    {
        static::assertSame($expected, HeaderValue::filter($value));
    }

    public static function validateValues(): array
    {
        return [
            ["This is a\n test",           'assertFalse'],
            ["This is a\r test",           'assertFalse'],
            ["This is a\n\r test",         'assertFalse'],
            ["This is a\r\n  test",        'assertTrue'],
            ["This is a\r\n\ttest",        'assertTrue'],
            ["This is a \r\ntest",         'assertFalse'],
            ["This is a \r\n\n test",      'assertFalse'],
            ["This is a\n\n test",         'assertFalse'],
            ["This is a\r\r test",         'assertFalse'],
            ["This is a \r\r\n test",      'assertFalse'],
            ["This is a \r\n\r\ntest",     'assertFalse'],
            ["This is a \r\n\n\r\n test",  'assertFalse'],
            ["This\tis\ta test",           'assertTrue'],
            ["This is\ta \r\n test",       'assertTrue'],
            ["This\tis\ta\ntest",          'assertFalse'],
            ["This is a \r\t\n \r\n test", 'assertFalse'],
            ["a\r\n ",                     'assertTrue'],
            ["a\r",                        'assertFalse'],
            ["a\r\n \n",                   'assertFalse'],
        ];
    }

    #[Test]
    #[DataProvider('validateValues')]
    #[Group('ZF2015-04')]
    public function validateValue(string $value, string $assertion): void
    {
        $this->{$assertion}(HeaderValue::isValid($value));
    }

    public static function assertValues(): array
    {
        return [
            ["This is a\n test"],
            ["This is a\r test"],
            ["This is a\n\r test"],
            ["This is a \r\ntest"],
            ["This is a \r\n\n test"],
            ["This is a\n\n test"],
            ["This is a\r\r test"],
            ["This is a \r\r\n test"],
            ["This is a \r\n\r\ntest"],
            ["This is a \r\n\n\r\n test"],
        ];
    }

    #[Test]
    #[DataProvider('assertValues')]
    #[Group('ZF2015-04')]
    public function assertValidRaisesExceptionForInvalidValues(string $value): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Invalid');
        HeaderValue::assertValid($value);
    }

    /**
     * DEL is a control character, not printable US-ASCII (RFC 5322, section 3.2.3).
     */
    #[Test]
    public function rejectsDelete(): void
    {
        static::assertFalse(HeaderValue::isValid("a\x7Fb"));
    }

    #[DataProvider('controlProvider')]
    #[Test]
    public function rejectsControlCharacter(string $value): void
    {
        static::assertFalse(HeaderValue::isValid($value));
    }

    #[Test]
    public function rejectsRawUtf8ForWritingAsItIs(): void
    {
        static::assertFalse(HeaderValue::isValid('Grüße'));
    }

    #[DataProvider('validUtf8Provider')]
    #[Test]
    public function acceptsRawUtf8WhenReading(string $value): void
    {
        static::assertTrue(HeaderValue::isValidUtf8($value));
    }

    #[DataProvider('invalidUtf8Provider')]
    #[Test]
    public function rejectsInvalidUtf8OrControlWhenReading(string $value): void
    {
        static::assertFalse(HeaderValue::isValidUtf8($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function controlProvider(): array
    {
        return [
            'NUL'    => ["a\x00b"],
            'escape' => ["a\x1Bb"],
            'US'     => ["a\x1Fb"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validUtf8Provider(): array
    {
        return [
            'ASCII'          => ['Hello, world'],
            'empty'          => [''],
            'tab'            => ["a\tb"],
            'Latin'          => ['Grüße'],
            'CJK'            => ['日本語'],
            'emoji'          => ["\u{1F600}"],
            'after C1 range' => ["\u{A0}"],
            'folded'         => ["Grüße\r\n aus\r\n\tBerlin"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUtf8Provider(): array
    {
        return [
            'Latin-1'            => ["Gr\xFC\xDFe"],
            'truncated'          => ["a\xC3"],
            'overlong'           => ["\xC0\xAF"],
            'surrogate'          => ["\xED\xA0\x80"],
            'NUL'                => ["a\x00b"],
            'DEL'                => ["a\x7Fb"],
            'first C1 control'   => ["a\u{80}b"],
            'last C1 control'    => ["a\u{9F}b"],
            'bare LF'            => ["a\nb"],
            'bare CR'            => ["a\rb"],
            'CRLF without space' => ["a\r\nBcc: evil@example.com"],
            'trailing CRLF'      => ["a\r\n"],
            'trailing LF'        => ["a\n"],
        ];
    }

    #[Test]
    public function acceptsTilde(): void
    {
        static::assertTrue(HeaderValue::isValid('a~b'));
    }

    #[Test]
    public function rejectsCarriageReturnLineFeedWithoutFoldingWhitespace(): void
    {
        static::assertFalse(HeaderValue::isValid("a\r\n"));
    }
}
