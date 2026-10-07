<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\EncodedWordDecoder;
use Contenir\Mail\Header\Exception\InvalidArgumentException;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Header\HeaderName;
use Contenir\Mail\Header\HeaderWrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function chr;
use function explode;
use function max;
use function str_repeat;
use function strlen;

#[CoversClass(GenericHeader::class)]
#[CoversClass(HeaderWrap::class)]
#[CoversClass(EncodedWordDecoder::class)]
#[Group('unit')]
final class GenericHeaderTest extends TestCase
{
    #[DataProvider('invalidHeaderLineProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function splitHeaderLineRejectsInvalidLine(string $line, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        GenericHeader::splitHeaderLine($line);
    }

    #[DataProvider('invalidHeaderLineProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function fromStringRejectsInvalidLine(string $line, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        GenericHeader::fromString($line);
    }

    /**
     * @param array{string, string} $expected
     */
    #[DataProvider('validHeaderLineProvider')]
    #[Test]
    public function splitHeaderLineSeparatesNameFromValue(string $line, array $expected): void
    {
        static::assertSame($expected, GenericHeader::splitHeaderLine($line));
    }

    #[DataProvider('invalidFieldNameProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function constructorRejectsInvalidFieldName(string $fieldName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header name must be composed of printable US-ASCII characters, except colon.');

        new GenericHeader($fieldName);
    }

    #[Test]
    public function constructorRejectsValueThatCannotBeEncoded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Header value must be composed of printable US-ASCII characters and valid folding sequences.',
        );

        new GenericHeader('Foo', "\xFF\xFE");
    }

    #[DataProvider('fieldNameProvider')]
    #[Test]
    public function normalisesFieldName(string $fieldName, string $expected): void
    {
        static::assertSame($expected, (new GenericHeader($fieldName, 'value'))->getFieldName());
    }

    #[DataProvider('fieldNameProvider')]
    #[Test]
    public function normalisesFieldNameFromString(string $fieldName, string $expected): void
    {
        static::assertSame($expected, GenericHeader::fromString("{$fieldName}: value")->getFieldName());
    }

    #[DataProvider('injectedValueProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function encodesLineBreaksInValueOnOutput(string $fieldValue): void
    {
        static::assertDoesNotMatchRegularExpression(
            '/(?<!\r)\n|\n(?! )/',
            (new GenericHeader('Foo', $fieldValue))->toString(),
            'Only folding, a CRLF followed by a space, may break the line',
        );
    }

    #[DataProvider('injectedValueProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function encodesCarriageReturnsInValueOnOutput(string $fieldValue): void
    {
        static::assertDoesNotMatchRegularExpression(
            '/\r(?!\n )/',
            (new GenericHeader('Foo', $fieldValue))->toString(),
            'Only folding, a CRLF followed by a space, may break the line',
        );
    }

    #[DataProvider('validFieldValueProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function decodesValueFromString(string $decodedValue, string $encodedValue): void
    {
        static::assertSame($decodedValue, GenericHeader::fromString("Foo:{$encodedValue}")->getFieldValue());
    }

    #[DataProvider('validFieldValueProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function keepsDecodedValueFromConstructor(string $decodedValue): void
    {
        static::assertSame($decodedValue, (new GenericHeader('Foo', $decodedValue))->getFieldValue());
    }

    #[DataProvider('validFieldValueProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function writesEncodedValue(string $decodedValue, string $encodedValue): void
    {
        static::assertSame("Foo: {$encodedValue}", (new GenericHeader('Foo', $decodedValue))->toString());
    }

    #[Group('ZF2015-04')]
    #[Test]
    public function keepsFoldingSequenceInDecodedValue(): void
    {
        static::assertSame("foo\r\n bar", (new GenericHeader('Foo', "foo\r\n bar"))->getFieldValue());
    }

    #[Group('ZF2015-04')]
    #[Test]
    public function encodesFoldingSequenceInEncodedValue(): void
    {
        static::assertSame(
            '=?UTF-8?Q?foo=0D=0A=20bar?=',
            (new GenericHeader('Foo', "foo\r\n bar"))->getEncodedFieldValue(),
        );
    }

    #[Group('ZF2015-04')]
    #[Test]
    public function writesFoldingSequenceEncoded(): void
    {
        static::assertSame('Foo: =?UTF-8?Q?foo=0D=0A=20bar?=', (new GenericHeader('Foo', "foo\r\n bar"))->toString());
    }

    #[Test]
    public function unfoldsContinuationLineFromString(): void
    {
        static::assertSame('foo bar', GenericHeader::fromString("Foo: foo\r\n bar")->getFieldValue());
    }

    #[Test]
    public function writesZeroValue(): void
    {
        static::assertSame('Foo: 0', (new GenericHeader('Foo', '0'))->toString());
    }

    #[Test]
    public function defaultsToEmptyValue(): void
    {
        static::assertSame('', (new GenericHeader('Foo'))->getFieldValue());
    }

    #[Test]
    public function parsesEmptyValueFromString(): void
    {
        static::assertSame('Foo: ', GenericHeader::fromString('Foo:')->toString());
    }

    #[Test]
    public function foldsLongAsciiValueOnOutput(): void
    {
        $value = str_repeat('word ', times: 20);

        static::assertSame(
            "X-Long: word word word word word word word word word word word word word word\r\n"
                . ' word word word word word word ',
            (new GenericHeader('X-Long', $value))->toString(),
        );
    }

    #[Test]
    public function writesAsciiValueWithoutEncoding(): void
    {
        static::assertSame('X-Test: test', GenericHeader::fromString('X-Test: test')->toString());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidHeaderLineProvider(): array
    {
        return [
            'space before colon'       => ['Content-Type' . chr(32) . ': text/html', 'Invalid header name detected'],
            'bare line feed in value'  => [
                'Content-Type: text/html; charset = "iso-8859-1"' . "\nThis is a test",
                'Invalid header value detected',
            ],
            'missing colon'            => ['content-type text/html', 'Header must match with the format "name:value"'],
            'empty line after value'   => ["Fake: foo-bar\r\n\r\nevilContent", 'Invalid header value detected'],
            'carriage return in value' => ["Fake: foo-bar\revilContent", 'Invalid header value detected'],
            'trailing carriage return' => ["Fake: foo-bar\r", 'Invalid header value detected'],
            'invalid UTF-8 in value'   => ["Fake: foo-bar\xE4", 'Invalid header value detected'],
            'control in value'         => ["Fake: foo\x00bar", 'Invalid header value detected'],
            'C1 control in value'      => ["Fake: foo\xC2\x85bar", 'Invalid header value detected'],
            'line feed without fold'   => ["Fake: foo-bar\r\nevilContent", 'Invalid header value detected'],
        ];
    }

    /**
     * @return array<string, array{string, array{string, string}}>
     */
    public static function validHeaderLineProvider(): array
    {
        return [
            'space after colon'    => ['Foo: bar', ['Foo', 'bar']],
            'no space after colon' => ['Foo:bar', ['Foo', 'bar']],
            'colon in value'       => ['Foo: bar: baz', ['Foo', 'bar: baz']],
            'folded with space'    => ["Foo: bar\r\n baz", ['Foo', "bar\r\n baz"]],
            'folded with tab'      => ["Foo: bar\r\n\tbaz", ['Foo', "bar\r\n\tbaz"]],
            'empty value'          => ['Foo:', ['Foo', '']],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFieldNameProvider(): array
    {
        return [
            'empty'          => [''],
            'append chr 13'  => ['Subject' . chr(13)],
            'append chr 127' => ['Subject' . chr(127)],
            'colon'          => ['Sub:ject'],
            'non-ASCII'      => ['Subjéct'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function fieldNameProvider(): array
    {
        return [
            'already canonical' => ['X-Custom', 'X-Custom'],
            'lower case'        => ['content-type', 'Content-Type'],
            'underscores'       => ['content_type', 'Content-Type'],
            'mixed separators'  => ['x_custom-name', 'X-Custom-Name'],
            'upper case kept'   => ['MIME-Version', 'MIME-Version'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedValueProvider(): array
    {
        return [
            'empty lines'             => ["\n\n\r\n\r\n\n"],
            'trailing newlines'       => ["Value\n\n\r\n\r\n\n"],
            'leading newlines'        => ["\n\n\r\n\r\n\nValue"],
            'surrounding newlines'    => ["\n\n\r\n\r\n\nValue\n\n\r\n\r\n\n"],
            'split value'             => ["Some\n\n\r\n\r\n\nValue"],
            'leading split value'     => ["\n\n\r\n\r\n\nSome\n\n\r\n\r\n\nValue"],
            'trailing split value'    => ["Some\n\n\r\n\r\n\nValue\n\n\r\n\r\n\n"],
            'surrounding split value' => ["\n\n\r\n\r\n\nSome\n\n\r\n\r\n\nValue\n\n\r\n\r\n\n"],
        ];
    }

    /**
     * Decoded value => encoded value.
     *
     * @return array<string, array{string, string}>
     */
    public static function validFieldValueProvider(): array
    {
        return [
            'ASCII'        => ['azAZ09-_', 'azAZ09-_'],
            'UTF-8'        => ['ázÁZ09-_', '=?UTF-8?Q?=C3=A1z=C3=81Z09-=5F?='],
            'newline'      => ["xxx yyy\n", '=?UTF-8?Q?xxx=20yyy=0A?='],
            'cr-lf'        => ["xxx yyy\r\n", '=?UTF-8?Q?xxx=20yyy=0D=0A?='],
            'double cr-lf' => ["xxx yyy\r\n\r\n", '=?UTF-8?Q?xxx=20yyy=0D=0A=0D=0A?='],
            'multiline'    => ["xxx\r\ny\r\nyy", '=?UTF-8?Q?xxx=0D=0Ay=0D=0Ayy?='],
        ];
    }

    #[Test]
    public function rejectsNameLongerThanMaximumLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header name must be at most 981 characters');

        new GenericHeader(str_repeat('X', HeaderName::MAX_LENGTH + 1), 'value');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valueAfterLongestNameProvider(): array
    {
        return [
            'empty'                  => [''],
            'one character'          => ['x'],
            'words'                  => ['hello world'],
            'long word'              => [str_repeat('a', times: 2000)],
            'many words'             => [str_repeat('ab ', times: 400)],
            'two-byte character'     => ["h\u{E9}llo"],
            'four-byte characters'   => [str_repeat("\u{1F600}", times: 40)],
            'leading space'          => [' lead'],
            'encoded-word lookalike' => ['=?x?='],
        ];
    }

    #[DataProvider('valueAfterLongestNameProvider')]
    #[Test]
    public function writesNoLineLongerThan998WithNameOfMaximumLength(string $value): void
    {
        $header = new GenericHeader(str_repeat('X', HeaderName::MAX_LENGTH), $value);

        static::assertLessThanOrEqual(998, max(array_map(strlen(...), explode("\r\n", $header->toString()))));
    }

    #[Test]
    public function fromStringKeepsReceivedNameLongerThanMaximumLength(): void
    {
        $name = str_repeat('X', times: 990);

        static::assertSame($name, GenericHeader::fromString("{$name}: value")->getFieldName());
    }
}
