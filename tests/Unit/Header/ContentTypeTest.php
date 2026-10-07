<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Header\Exception\InvalidArgumentException;
use Contenir\Mail\Header\MimeParameterParser;
use Contenir\Mail\Header\MimeParameters;
use Contenir\Mail\Header\ParameterText;
use Contenir\Mail\Header\SafeText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;

#[CoversClass(ContentType::class)]
#[CoversClass(MimeParameters::class)]
#[CoversClass(MimeParameterParser::class)]
#[CoversClass(ParameterText::class)]
#[CoversClass(SafeText::class)]
#[Group('unit')]
final class ContentTypeTest extends TestCase
{
    #[Group('6491')]
    #[Test]
    public function ignoresTrailingSemicolonAfterQuotedParameter(): void
    {
        $header = ContentType::fromString(
            'Content-Type: multipart/alternative; boundary="Apple-Mail=_1B852F10-F9C6-463D-AADD-CD503A5428DD";',
        );

        static::assertSame(
            ['boundary' => 'Apple-Mail=_1B852F10-F9C6-463D-AADD-CD503A5428DD'],
            $header->getParameters(),
        );
    }

    #[Test]
    public function ignoresTrailingSemicolonWithoutSpaceBeforeParameter(): void
    {
        $header = ContentType::fromString('Content-Type: application/pdf;name="foo.pdf";');

        static::assertSame(['name' => 'foo.pdf'], $header->getParameters());
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('literalProvider')]
    #[Test]
    public function keepsSpecialsInsideQuotedParameter(string $fieldValue, array $expected): void
    {
        static::assertSame($expected, ContentType::fromString("Content-Type: {$fieldValue}")->getParameters());
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('headerProvider')]
    #[Test]
    public function parsesType(string $type, array $parameters, string $fieldValue, string $headerLine): void
    {
        static::assertSame($type, ContentType::fromString($headerLine)->getType());
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('headerProvider')]
    #[Test]
    public function parsesParameters(string $type, array $parameters, string $fieldValue, string $headerLine): void
    {
        static::assertSame($parameters, ContentType::fromString($headerLine)->getParameters());
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('headerProvider')]
    #[Test]
    public function writesDecodedFieldValue(string $type, array $parameters, string $fieldValue): void
    {
        static::assertSame($fieldValue, (new ContentType($type, $parameters))->getFieldValue());
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('headerProvider')]
    #[Test]
    public function writesHeaderLine(string $type, array $parameters, string $fieldValue, string $headerLine): void
    {
        static::assertSame($headerLine, (new ContentType($type, $parameters))->toString());
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('headerProvider')]
    #[Test]
    public function roundTripsHeaderLine(string $type, array $parameters, string $fieldValue, string $headerLine): void
    {
        static::assertSame($headerLine, ContentType::fromString($headerLine)->toString());
    }

    #[Test]
    public function writesFieldNameContentType(): void
    {
        static::assertSame('Content-Type', (new ContentType('text/plain'))->getFieldName());
    }

    #[Test]
    public function encodedFieldValueEncodesNonAsciiParameter(): void
    {
        static::assertSame(
            "foo/baz;\r\n name*=UTF-8''%C3%93",
            (new ContentType('foo/baz', ['name' => 'Ó']))->getEncodedFieldValue(),
        );
    }

    #[Test]
    public function encodedFieldValueLeavesAsciiParameterPlain(): void
    {
        static::assertSame(
            "text/plain;\r\n charset=\"UTF-8\"",
            (new ContentType('text/plain', ['charset' => 'UTF-8']))->getEncodedFieldValue(),
        );
    }

    #[DataProvider('invalidHeaderLinesProvider')]
    #[Test]
    public function fromStringRejectsInvalidHeaderLine(string $headerLine, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ContentType::fromString($headerLine);
    }

    #[Group('ZF2015-04')]
    #[Test]
    public function fromStringReadsFoldedType(): void
    {
        static::assertSame('text/html', ContentType::fromString("Content-Type: text/html;\r\n level=1")->getType());
    }

    #[Group('ZF2015-04')]
    #[Test]
    public function fromStringReadsFoldedParameters(): void
    {
        static::assertSame(
            ['level' => '1'],
            ContentType::fromString("Content-Type: text/html;\r\n level=1")->getParameters(),
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('parameterWrappingProvider')]
    #[Test]
    public function decodesRfc2231ExtendedParameter(string $headerLine, array $parameters): void
    {
        static::assertSame($parameters, ContentType::fromString($headerLine)->getParameters());
    }

    #[DataProvider('invalidTypeProvider')]
    #[Test]
    public function rejectsInvalidType(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Content-Type expects a value in the format \"type/subtype\"; received \"{$type}\"",
        );

        new ContentType($type);
    }

    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsParameterNameWithCrlf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid content-type parameter name detected');

        new ContentType('text/html', ["b\r\na\rr\n" => 'baz']);
    }

    #[Group('ZF2015-04')]
    #[Test]
    public function withParameterRejectsParameterNameWithCrlf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid content-type parameter name detected');

        (new ContentType('text/html'))->withParameter("b\r\na\rr\n", value: 'baz');
    }

    #[Test]
    public function rejectsParameterValueThatCannotBeEncoded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parameter value must be composed of printable US-ASCII or UTF-8 characters.');

        new ContentType('text/html', ['name' => "\xFF\xFE"]);
    }

    #[Test]
    public function trimsParameterNames(): void
    {
        static::assertSame(['charset' => 'x'], (new ContentType('text/plain', [' Charset ' => 'x']))->getParameters());
    }

    #[Test]
    public function lowerCasesParameterNames(): void
    {
        static::assertSame(
            ['charset' => 'UTF-8'],
            ContentType::fromString('Content-Type: text/plain; Charset=UTF-8')->getParameters(),
        );
    }

    #[Test]
    public function getParameterReturnsValue(): void
    {
        static::assertSame(
            'top',
            ContentType::fromString('content-type: text/plain; level=top')->getParameter('level'),
        );
    }

    #[Test]
    public function getParameterIgnoresCase(): void
    {
        static::assertSame(
            'top',
            ContentType::fromString('content-type: text/plain; level=top')->getParameter('LEVEL'),
        );
    }

    #[Test]
    public function getParameterTrimsQuotesAndSpaces(): void
    {
        $header = ContentType::fromString('content-type: text/plain; level=top; name="logfile.log";');

        static::assertSame('logfile.log', $header->getParameter('name'));
    }

    #[Test]
    public function getParameterReturnsNullWhenMissing(): void
    {
        static::assertNull(ContentType::fromString('content-type: text/plain')->getParameter('level'));
    }

    #[Test]
    public function withTypeChangesType(): void
    {
        static::assertSame(
            'text/html',
            (new ContentType('text/plain'))->withType('text/html')
                ->getType(),
        );
    }

    #[Test]
    public function withTypeKeepsParameters(): void
    {
        $header = (new ContentType('text/plain', ['charset' => 'UTF-8']))->withType('text/html');

        static::assertSame(['charset' => 'UTF-8'], $header->getParameters());
    }

    #[Test]
    public function withTypeLeavesOriginalUnchanged(): void
    {
        $header = new ContentType('text/plain');
        $header->withType('text/html');

        static::assertSame('text/plain', $header->getType());
    }

    #[Test]
    public function withTypeRejectsInvalidType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Content-Type expects a value in the format "type/subtype"; received "invalid"');

        (new ContentType('text/plain'))->withType('invalid');
    }

    #[Test]
    public function withParameterAddsParameter(): void
    {
        $header = (new ContentType('text/plain', ['charset' => 'UTF-8']))->withParameter('Format', value: 'flowed');

        static::assertSame(['charset' => 'UTF-8', 'format' => 'flowed'], $header->getParameters());
    }

    #[Test]
    public function withParameterReplacesParameterWhateverItsCase(): void
    {
        $header = (new ContentType('text/plain', ['charset' => 'UTF-8']))->withParameter('CHARSET', value: 'us-ascii');

        static::assertSame(['charset' => 'us-ascii'], $header->getParameters());
    }

    #[Test]
    public function withParameterLeavesOriginalUnchanged(): void
    {
        $header = new ContentType('text/plain');
        $header->withParameter('charset', value: 'UTF-8');

        static::assertSame([], $header->getParameters());
    }

    #[Test]
    public function withoutParameterRemovesParameter(): void
    {
        $header = ContentType::fromString('content-type: text/plain; level=top')->withoutParameter('Level');

        static::assertSame([], $header->getParameters());
    }

    #[Test]
    public function withoutParameterIgnoresMissingParameter(): void
    {
        $header = ContentType::fromString('content-type: text/plain; level=top')->withoutParameter('name');

        static::assertSame(['level' => 'top'], $header->getParameters());
    }

    #[Test]
    public function withoutParameterLeavesOriginalUnchanged(): void
    {
        $header = ContentType::fromString('content-type: text/plain; level=top');
        $header->withoutParameter('level');

        static::assertSame(['level' => 'top'], $header->getParameters());
    }

    #[DataProvider('unconventionalHeaderLinesProvider')]
    #[Test]
    public function fromStringAcceptsUnconventionalNames(string $headerLine): void
    {
        static::assertSame('text/plain', ContentType::fromString($headerLine)->getFieldValue());
    }

    #[DataProvider('unconventionalHeaderLinesProvider')]
    #[Test]
    public function fromStringWritesCanonicalName(string $headerLine): void
    {
        static::assertSame('Content-Type', ContentType::fromString($headerLine)->getFieldName());
    }

    /**
     * Header injection: type and subtype are RFC 2045 tokens.
     */
    #[DataProvider('injectedTypeProvider')]
    #[Test]
    public function rejectsTypeCarryingInjection(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Content-Type expects a value in the format "type/subtype"');

        new ContentType($type);
    }

    /**
     * Header injection: parameter names are RFC 2045 tokens.
     */
    #[DataProvider('invalidParameterNameProvider')]
    #[Test]
    public function rejectsParameterNameThatIsNotAToken(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid content-type parameter name detected');

        new ContentType('text/plain', [$name => 'x']);
    }

    /**
     * Header injection: a value that would end the quoted string early is escaped.
     */
    #[Test]
    public function escapesQuoteInCharset(): void
    {
        static::assertSame(
            "Content-Type: text/plain;\r\n charset=\"x\\\"; boundary=\\\"y\"",
            (new ContentType('text/plain', ['charset' => 'x"; boundary="y']))->toString(),
        );
    }

    #[Test]
    public function rejectsParameterValueWithLineBreak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parameter value must be composed of printable US-ASCII or UTF-8 characters.');

        new ContentType('text/plain', ['charset' => "UTF-8\r\nBcc: victim@example.com"]);
    }

    /**
     * RFC 2047 text is decoded only after the parameters are split, so what it
     * decodes to cannot add a parameter or end a value.
     */
    #[DataProvider('smuggledStructureProvider')]
    #[Test]
    public function decodesEncodedWordsOnlyInsideParameterValues(string $headerLine, array $expected): void
    {
        static::assertSame($expected, ContentType::fromString($headerLine)->getParameters());
    }

    #[Test]
    public function keepsEncodedWordInTypeAsWrittenSoTheTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Content-Type expects a value in the format "type/subtype"');

        ContentType::fromString('Content-Type: =?UTF-8?Q?text/html=3B_charset=3Dx?=');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedTypeProvider(): array
    {
        return [
            'line break in subtype' => ["text/html\r\nBcc: victim@example.com"],
            'parameter in subtype'  => ['text/html; charset=x'],
            'quote in type'         => ['te"xt/html'],
            'empty subtype'         => ['text/'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidParameterNameProvider(): array
    {
        return [
            'equals sign'     => ['a=b'],
            'semicolon'       => ['a;b'],
            'quote'           => ['a"b'],
            'space'           => ['a b'],
            'RFC 2231 marker' => ['name*'],
            'empty'           => [''],
            'non-ASCII'       => ['näme'],
        ];
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function smuggledStructureProvider(): array
    {
        return [
            'semicolon and equals in encoded word' => [
                'Content-Type: text/plain; name="=?UTF-8?Q?a=3B_charset=3Devil?="',
                ['name' => 'a; charset=evil'],
            ],
            'quote in encoded word'                => [
                'Content-Type: text/plain; name="=?UTF-8?Q?a=22?="; charset=x',
                ['name' => 'a"', 'charset' => 'x'],
            ],
            'line break in extended value'         => [
                "Content-Type: text/plain; name*=UTF-8''a%0D%0ABcc:%20x",
                ['name' => 'aBcc: x'],
            ],
        ];
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function literalProvider(): array
    {
        return [
            'semicolon' => ['text/plain; name="foo; bar.txt"', ['name' => 'foo; bar.txt']],
            'ampersand' => ['text/plain; name="foo&bar.txt"', ['name' => 'foo&bar.txt']],
        ];
    }

    /**
     * @return array<string, array{string, array<string, string>, string, string}>
     */
    public static function headerProvider(): array
    {
        return [
            // @group #2728
            'foo/a.b-c'                    => ['foo/a.b-c', [], 'foo/a.b-c', 'Content-Type: foo/a.b-c'],
            'foo/a+b'                      => ['foo/a+b', [], 'foo/a+b', 'Content-Type: foo/a+b'],
            'foo/baz'                      => ['foo/baz', [], 'foo/baz', 'Content-Type: foo/baz'],
            'parameter use header folding' => [
                'foo/baz',
                ['charset' => 'us-ascii'],
                'foo/baz; charset="us-ascii"',
                "Content-Type: foo/baz;\r\n charset=\"us-ascii\"",
            ],
            'two parameters'               => [
                'multipart/mixed',
                ['boundary' => 'xyz', 'charset' => 'UTF-8'],
                'multipart/mixed; boundary="xyz"; charset="UTF-8"',
                "Content-Type: multipart/mixed;\r\n boundary=\"xyz\";\r\n charset=\"UTF-8\"",
            ],
            'encoded characters'           => [
                'foo/baz',
                ['name' => 'Ó'],
                'foo/baz; name="Ó"',
                "Content-Type: foo/baz;\r\n name*=UTF-8''%C3%93",
            ],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidHeaderLinesProvider(): array
    {
        return [
            'another header' => ['Foo: bar', 'Invalid header line for Content-Type string'],
            // @group ZF2015-04
            'space before colon'   => ['Content-Type' . chr(32) . ': text/html', 'Invalid header name detected'],
            'newline'              => ["Content-Type: text/html;\nlevel=1", 'Invalid header value detected'],
            'cr-lf'                => ["Content-Type: text/html\r\n;level=1", 'Invalid header value detected'],
            'multiline'            => ["Content-Type: text/html;\r\nlevel=1\r\nq=0.1", 'Invalid header value detected'],
            'type without subtype' => [
                'Content-Type: text',
                'Content-Type expects a value in the format "type/subtype"',
            ],
        ];
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function parameterWrappingProvider(): array
    {
        return [
            'example from RFC 2231' => [
                "Content-Type: application/x-stuff; title*=us-ascii'en-us'This%20is%20%2A%2A%2Afun%2A%2A%2A",
                ['title' => 'This is ***fun***'],
            ],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidTypeProvider(): array
    {
        return [
            'no subtype'   => ['invalid'],
            'empty'        => [''],
            'two slashes'  => ['text/plain/extra'],
            'space inside' => ['text/ plain'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unconventionalHeaderLinesProvider(): array
    {
        return [
            'contenttype'  => ['ContentType: text/plain'],
            'content_type' => ['Content_Type: text/plain'],
            'lower case'   => ['content-type: text/plain'],
        ];
    }
}
