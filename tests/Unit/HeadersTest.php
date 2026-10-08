<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use ArrayIterator;
use Contenir\Mail\AddressList;
use Contenir\Mail\Exception\RuntimeException;
use Contenir\Mail\Header;
use Contenir\Mail\Header\Exception\InvalidArgumentException;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Header\HeaderBlock;
use Contenir\Mail\Header\HeaderLines;
use Contenir\Mail\Header\HeaderLocator;
use Contenir\Mail\Header\HeaderName;
use Contenir\Mail\Header\HeaderParser;
use Contenir\Mail\Header\MimeParameterParser;
use Contenir\Mail\Headers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function iterator_to_array;
use function str_repeat;

#[CoversClass(Headers::class)]
#[CoversClass(HeaderParser::class)]
#[CoversClass(HeaderBlock::class)]
#[CoversClass(HeaderLines::class)]
#[CoversClass(MimeParameterParser::class)]
#[Group('unit')]
final class HeadersTest extends TestCase
{
    private const string RECEIVED_FIRST =
        "from framework (localhost [127.0.0.1])\r\n by framework (Postfix) with ESMTP id BBBBBBBBBBB\r\n"
            . ' for <laminas@framework>; Mon, 21 Nov 2011 12:50:27 -0600 (CST)';

    private const string RECEIVED_SECOND =
        "from framework (localhost [127.0.0.1])\r\n by framework (Postfix) with ESMTP id AAAAAAAAAAA\r\n"
            . ' for <laminas@framework>; Mon, 21 Nov 2011 12:50:29 -0600 (CST)';

    private const string RECEIVED_FIRST_UNFOLDED =
        'from framework (localhost [127.0.0.1]) by framework (Postfix) with ESMTP id BBBBBBBBBBB'
            . ' for <laminas@framework>; Mon, 21 Nov 2011 12:50:27 -0600 (CST)';

    private const string RECEIVED_SECOND_UNFOLDED =
        'from framework (localhost [127.0.0.1]) by framework (Postfix) with ESMTP id AAAAAAAAAAA'
            . ' for <laminas@framework>; Mon, 21 Nov 2011 12:50:29 -0600 (CST)';

    #[Test]
    public function startsEmpty(): void
    {
        static::assertSame([], (new Headers())->toList());
    }

    #[Test]
    public function keepsConstructorHeadersInOrder(): void
    {
        $foo = new GenericHeader('Foo', 'bar');
        $baz = new GenericHeader('Baz', 'baz');

        static::assertSame([$foo, $baz], (new Headers($foo, $baz))->toList());
    }

    #[Test]
    public function countsHeaders(): void
    {
        $headers = new Headers(new GenericHeader('Foo', 'bar'), new GenericHeader('Foo', 'baz'));

        static::assertCount(2, $headers);
    }

    #[Test]
    public function iteratesHeadersInOrder(): void
    {
        $foo = new GenericHeader('Foo', 'bar');
        $baz = new GenericHeader('Baz', 'baz');

        static::assertSame([0 => $foo, 1 => $baz], iterator_to_array(new Headers($foo, $baz)));
    }

    #[Test]
    public function iteratorIsArrayIterator(): void
    {
        static::assertInstanceOf(ArrayIterator::class, (new Headers())->getIterator());
    }

    /**
     * @param array<string, string|list<string>> $expected
     */
    #[DataProvider('headerBlockProvider')]
    #[Test]
    public function parsesHeaderBlock(string $block, array $expected): void
    {
        static::assertSame($expected, Headers::fromString($block)->toArray());
    }

    #[Test]
    #[Group('6657')]
    public function joinsContinuationLineIntoOneHeader(): void
    {
        static::assertCount(1, Headers::fromString("Fake: foo-bar,\r\n      blah-blah"));
    }

    #[Test]
    public function parsesBlockWithCustomLineEnding(): void
    {
        static::assertSame(
            ['Fake' => 'foo-bar continued', 'Other' => 'x'],
            Headers::fromString("Fake: foo-bar\n continued\nOther: x", eol: "\n")->toArray(),
        );
    }

    #[Test]
    public function parsesUnknownHeaderAsGenericHeader(): void
    {
        static::assertInstanceOf(GenericHeader::class, Headers::fromString('Fake: foo-bar')->get('fake'));
    }

    #[DataProvider('knownHeaderProvider')]
    #[Test]
    public function parsesKnownHeaderWithItsOwnClass(string $line, string $name, string $class): void
    {
        static::assertInstanceOf($class, Headers::fromString($line)->get($name));
    }

    /**
     * RFC 6532 lets stored and received mail carry header values in raw UTF-8.
     *
     * @param class-string $class
     */
    #[DataProvider('rawUtf8HeaderProvider')]
    #[Test]
    public function parsesRawUtf8IntoHeaderClass(string $line, string $class, string $value): void
    {
        $header = iterator_to_array(Headers::fromString($line))[0];

        static::assertSame([$class, $value], [$header::class, $header->getFieldValue()]);
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('rawUtf8HeaderProvider')]
    #[Test]
    public function writesRawUtf8AsEncodedWords(string $line, string $class, string $value, string $written): void
    {
        static::assertSame($written . Headers::EOL, Headers::fromString($line)->toString());
    }

    #[DataProvider('invalidRawHeaderProvider')]
    #[Test]
    public function rejectsHeaderValueWithControlCharacters(string $block): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header value detected');

        Headers::fromString($block);
    }

    #[Test]
    public function fallsBackToGenericHeaderWhenHeaderClassRejectsValue(): void
    {
        static::assertInstanceOf(GenericHeader::class, Headers::fromString('Date: not a date')->get('date'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAddressHeaderProvider(): array
    {
        return [
            'Sender' => ['Sender: foo'],
            'From'   => ['From: @@@'],
            'To'     => ['To: <<<'],
        ];
    }

    #[DataProvider('invalidAddressHeaderProvider')]
    #[Test]
    public function fallsBackToGenericHeaderWhenAddressIsInvalid(string $line): void
    {
        static::assertSame(
            [[GenericHeader::class, $line]],
            array_map(
                static fn(Header\HeaderInterface $header): array => [$header::class, $header->toString()],
                Headers::fromString($line)->toList(),
            ),
        );
    }

    #[DataProvider('invalidAddressHeaderProvider')]
    #[Test]
    public function fallsBackToGenericHeaderWhenGivenLineHasInvalidAddress(string $line): void
    {
        static::assertInstanceOf(GenericHeader::class, Headers::fromIterable([$line])->toList()[0] ?? null);
    }

    #[Test]
    public function parsesBlockWithNameOfMaximumLength(): void
    {
        $block = str_repeat('X', HeaderName::MAX_LENGTH) . ": value\r\nSubject: Hello";

        static::assertSame(
            [str_repeat('X', HeaderName::MAX_LENGTH) => 'value', 'Subject' => 'Hello'],
            Headers::fromString($block)->toArray(),
        );
    }

    /**
     * Such a name leaves no room for the colon in a 998-character line.
     *
     * @return array<string, array{int}>
     */
    public static function receivedLongNameProvider(): array
    {
        return [
            'one past the limit' => [HeaderName::MAX_LENGTH + 1],
            'longer than a line' => [1200],
        ];
    }

    #[DataProvider('receivedLongNameProvider')]
    #[Test]
    public function rejectsBlockWithNameLongerThanMaximumLength(int $length): void
    {
        $this->expectException(Header\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Header name must be at most 997 characters');

        Headers::fromString(str_repeat('X', $length) . ": value\r\nSubject: Hello");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function receivedLongLineProvider(): array
    {
        return [
            'value filling the line'     => [str_repeat('X', times: 991) . ': value'],
            'name and colon only'        => [str_repeat('X', HeaderName::MAX_LENGTH) . ':'],
            'name, colon and space'      => [str_repeat('X', HeaderName::MAX_LENGTH - 1) . ': '],
            'value on the next line'     => [str_repeat('X', HeaderName::MAX_LENGTH) . ":\r\n value"],
            'encoded value on next line' => [str_repeat('X', HeaderName::MAX_LENGTH) . ":\r\n =?UTF-8?Q?caf=C3=A9?="],
        ];
    }

    #[DataProvider('receivedLongLineProvider')]
    #[Test]
    public function writesReceivedLineWithLongNameBackAsReceived(string $line): void
    {
        $block = "{$line}\r\nSubject: Hello\r\n";

        static::assertSame($block, Headers::fromString($block)->toString());
    }

    /**
     * A received line longer than 998 characters is written again, with its value on the next line.
     */
    #[Test]
    public function rewritesReceivedLineTooLongToWriteBack(): void
    {
        $name = str_repeat('X', HeaderName::MAX_LENGTH);

        static::assertSame(
            "{$name}:\r\n =?UTF-8?Q?value?=\r\n",
            Headers::fromString("{$name}: value")->toString(),
        );
    }

    /**
     * @return array<string, array{iterable<int|string, string|array{string, string}>}>
     */
    public static function builtLongNameProvider(): array
    {
        $name = str_repeat('X', HeaderName::MAX_LENGTH + 1);

        return [
            'name => value' => [[$name => 'value']],
            '[name, value]' => [[[$name, 'value']]],
            'line'          => [["{$name}: value"]],
        ];
    }

    /**
     * @param iterable<int|string, string|array{string, string}> $headers
     */
    #[DataProvider('builtLongNameProvider')]
    #[Test]
    public function rejectsBuiltNameLongerThanLimit(iterable $headers): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header name must be at most 997 characters');

        Headers::fromIterable($headers);
    }

    #[Test]
    public function buildsNameOfMaximumLength(): void
    {
        $name = str_repeat('X', HeaderName::MAX_LENGTH);

        static::assertSame([$name => 'value'], Headers::fromIterable([$name => 'value'])->toArray());
    }

    #[Test]
    public function keepsRejectedValueInGenericHeaderFallback(): void
    {
        static::assertSame('Date: not a date' . Headers::EOL, Headers::fromString('Date: not a date')->toString());
    }

    #[Test]
    public function parsesWithInjectedLocator(): void
    {
        $locator = new HeaderLocator(['Subject' => GenericHeader::class]);

        static::assertInstanceOf(
            GenericHeader::class,
            Headers::fromString('Subject: Hello', locator: $locator)->get('subject'),
        );
    }

    #[DataProvider('lineNotMatchingHeaderFormatProvider')]
    #[Test]
    public function strictFieldsRejectLineNotMatchingHeaderFormat(string $block, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        HeaderBlock::fields($block, Headers::EOL);
    }

    /**
     * A line that is not a header is dropped when reading, with its continuation lines,
     * so it does not make the message unreadable (laminas/laminas-mail#76, #221).
     */
    #[DataProvider('skippedLineProvider')]
    #[Test]
    public function skipsLineNotMatchingHeaderFormatWhenReading(string $block, string $expected): void
    {
        static::assertSame($expected, Headers::fromString($block)->toString());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function skippedLineProvider(): array
    {
        return [
            'no colon'                           => ["Fake = foo-bar\r\nSubject: x", "Subject: x\r\n"],
            'continuation first'                 => [" leading: x\r\nSubject: x", "Subject: x\r\n"],
            'space in name'                      => ["Subject: x\r\nFake Name: y", "Subject: x\r\n"],
            'with its continuation lines'        => [
                "Subject: x\r\nnot a header\r\n more of it\r\nTo: jo@example.org",
                "Subject: x\r\nTo: jo@example.org\r\n",
            ],
            'keeping the header before it whole' => [
                "Subject: one\r\n two\r\nnot a header",
                "Subject: one\r\n two\r\n",
            ],
        ];
    }

    #[DataProvider('malformedBlockProvider')]
    #[Test]
    public function rejectsMalformedBlock(string $block): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed header detected');

        Headers::fromString($block);
    }

    #[Group('ZF2015-04')]
    #[Test]
    public function dropsContentAfterBlankLineInBlock(): void
    {
        static::assertSame("Fake: foo-bar\r\n", Headers::fromString("Fake: foo-bar\r\n\r\nevilContent")->toString());
    }

    /**
     * @param iterable<int|string, Header\HeaderInterface|string|array{string, string}> $headers
     */
    #[DataProvider('injectedIterableProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function fromIterableRejectsInjectedLineBreaks(iterable $headers): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header value detected');

        Headers::fromIterable($headers);
    }

    #[Test]
    public function fromIterableRejectsLineWithoutColon(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header must match with the format "name:value"');

        Headers::fromIterable(['Foo']);
    }

    /**
     * @param iterable<int|string, Header\HeaderInterface|string|array{string, string}> $headers
     */
    #[DataProvider('iterableProvider')]
    #[Test]
    public function buildsFromIterable(iterable $headers): void
    {
        static::assertSame(['Foo' => 'bar', 'Baz' => 'baz'], Headers::fromIterable($headers)->toArray());
    }

    #[Test]
    public function fromIterableKeepsHeaderObjects(): void
    {
        $header = new GenericHeader('Foo', 'bar');

        static::assertSame($header, Headers::fromIterable([$header])->get('Foo'));
    }

    #[Test]
    public function fromIterableUsesInjectedLocator(): void
    {
        $locator = new HeaderLocator(['Subject' => GenericHeader::class]);

        static::assertInstanceOf(
            GenericHeader::class,
            Headers::fromIterable(['Subject' => 'Hello'], $locator)->get('subject'),
        );
    }

    #[DataProvider('equivalentNameProvider')]
    #[Test]
    public function getMatchesEquivalentName(string $name): void
    {
        $header = new GenericHeader('Content-Type', 'text/plain');

        static::assertSame($header, (new Headers($header))->get($name));
    }

    #[DataProvider('equivalentNameProvider')]
    #[Test]
    public function hasMatchesEquivalentName(string $name): void
    {
        static::assertTrue((new Headers(new GenericHeader('Content-Type', 'text/plain')))->has($name));
    }

    #[Test]
    public function getReturnsNullForMissingHeader(): void
    {
        static::assertNull(Headers::fromString('Foo: bar')->get('foobar'));
    }

    #[Test]
    public function hasIsFalseForMissingHeader(): void
    {
        static::assertFalse(Headers::fromString('Foo: bar')->has('foobar'));
    }

    #[Test]
    public function hasComparesNamesStrictly(): void
    {
        static::assertFalse(Headers::fromString('000: foo-bar')->has('0'));
    }

    #[Test]
    public function getComparesNamesStrictly(): void
    {
        static::assertNull(Headers::fromString('000: foo-bar')->get('0'));
    }

    #[Test]
    public function getReturnsFirstOfSameNamedHeaders(): void
    {
        $first = new GenericHeader('Foo', 'one');

        static::assertSame($first, (new Headers($first, new GenericHeader('Foo', 'two')))->get('foo'));
    }

    #[Test]
    public function allReturnsSameNamedHeadersInOrder(): void
    {
        $headers = Headers::fromString("Foo: one\r\nBar: x\r\nfoo: two");

        static::assertSame(
            ['one', 'two'],
            array_map(
                static fn(Header\HeaderInterface $header): string => $header->getFieldValue(),
                $headers->all('Foo'),
            ),
        );
    }

    #[Test]
    public function allReturnsEmptyListForMissingHeader(): void
    {
        static::assertSame([], Headers::fromString('Foo: bar')->all('Baz'));
    }

    #[Test]
    public function withAppendsNewHeader(): void
    {
        $headers = Headers::fromString('Foo: bar')->with(new GenericHeader('Baz', 'baz'));

        static::assertSame("Foo: bar\r\nBaz: baz\r\n", $headers->toString());
    }

    #[Test]
    public function withReplacesSameNamedHeaderInPlace(): void
    {
        $headers = Headers::fromString("Foo: one\r\nBar: x\r\nFoo: two\r\nBaz: y")
            ->with(new GenericHeader('foo', 'new'));

        static::assertSame("Foo: new\r\nBar: x\r\nBaz: y\r\n", $headers->toString());
    }

    #[Test]
    public function withReplacesHeaderAtPositionOfFirstMatch(): void
    {
        $headers = Headers::fromString("Bar: x\r\nFoo: one\r\nBaz: y\r\nFoo: two")
            ->with(new GenericHeader('Foo', 'new'));

        static::assertSame("Bar: x\r\nFoo: new\r\nBaz: y\r\n", $headers->toString());
    }

    #[Test]
    public function withLeavesOriginalUnchanged(): void
    {
        $headers = Headers::fromString('Foo: bar');
        $headers->with(new GenericHeader('Foo', 'new'));

        static::assertSame("Foo: bar\r\n", $headers->toString());
    }

    #[Test]
    public function withAddedKeepsSameNamedHeaders(): void
    {
        $headers = (new Headers(Header\Received::fromString('Received: '
            . self::RECEIVED_FIRST)))->withAdded(Header\Received::fromString('Received: ' . self::RECEIVED_SECOND));

        static::assertCount(2, $headers->all('received'));
    }

    #[Test]
    public function withAddedAppendsAfterExistingHeaders(): void
    {
        $headers = Headers::fromString("Foo: one\r\nBar: x")->withAdded(new GenericHeader('Foo', 'two'));

        static::assertSame("Foo: one\r\nBar: x\r\nFoo: two\r\n", $headers->toString());
    }

    #[Test]
    public function withAddedLeavesOriginalUnchanged(): void
    {
        $headers = Headers::fromString('Foo: bar');
        $headers->withAdded(new GenericHeader('Baz', 'baz'));

        static::assertCount(1, $headers);
    }

    #[Test]
    public function withoutRemovesEveryInstanceOfHeader(): void
    {
        $headers = Headers::fromIterable([['Foo', 'foo'], ['Foo', 'bar'], 'Baz' => 'baz'])->without('foo');

        static::assertSame(['Baz' => 'baz'], $headers->toArray());
    }

    #[Test]
    public function withoutMatchesEquivalentName(): void
    {
        $headers = Headers::fromString("Content-Type: text/plain\r\nFoo: bar")->without('content_type');

        static::assertSame(['Foo' => 'bar'], $headers->toArray());
    }

    #[Test]
    public function withoutMissingHeaderKeepsEveryHeader(): void
    {
        static::assertCount(2, Headers::fromString("Foo: bar\r\nBaz: baz")->without('Missing'));
    }

    #[Test]
    public function withoutOnEmptyHeadersIsEmpty(): void
    {
        static::assertCount(0, (new Headers())->without(''));
    }

    #[Test]
    public function withoutLeavesOriginalUnchanged(): void
    {
        $headers = new Headers(new Header\Bcc());
        $headers->without('Bcc');

        static::assertTrue($headers->has('Bcc'));
    }

    #[Test]
    public function writesOneLinePerHeader(): void
    {
        static::assertSame(
            "Foo: bar\r\nBaz: baz\r\n",
            Headers::fromIterable(['Foo' => 'bar', 'Baz' => 'baz'])->toString(),
        );
    }

    #[Test]
    public function writesEmptyStringForNoHeaders(): void
    {
        static::assertSame('', (new Headers())->toString());
    }

    #[Test]
    public function omitsHeaderWithEmptyOutput(): void
    {
        static::assertSame("Foo: bar\r\n", (new Headers(new Header\To(), new GenericHeader('Foo', 'bar')))->toString());
    }

    #[Test]
    public function writesEverySameNamedHeader(): void
    {
        $headers = Headers::fromIterable([
            'Received: ' . self::RECEIVED_FIRST,
            'Received: ' . self::RECEIVED_SECOND,
        ]);

        static::assertSame(
            'Received: ' . self::RECEIVED_FIRST_UNFOLDED . "\r\nReceived: " . self::RECEIVED_SECOND_UNFOLDED . "\r\n",
            $headers->toString(),
        );
    }

    #[Test]
    public function arrayMapsNamesToValues(): void
    {
        static::assertSame(
            ['Foo' => 'bar', 'Baz' => 'baz'],
            Headers::fromIterable(['Foo' => 'bar', 'Baz' => 'baz'])->toArray(),
        );
    }

    #[Test]
    public function arrayListsValuesOfSameNamedHeaders(): void
    {
        $headers = Headers::fromIterable([
            'Received: ' . self::RECEIVED_FIRST,
            'Received: ' . self::RECEIVED_SECOND,
        ]);

        static::assertSame(
            ['Received' => [self::RECEIVED_FIRST_UNFOLDED, self::RECEIVED_SECOND_UNFOLDED]],
            $headers->toArray(),
        );
    }

    /**
     * @see https://github.com/zendframework/zend-mail/pull/61
     */
    #[Test]
    public function arrayHoldsDecodedValues(): void
    {
        $headers = Headers::fromString('Subject: =?ISO-8859-2?Q?PD=3A_My=3A_Go=B3?= =?ISO-8859-2?Q?blahblah?=');

        static::assertSame(['Subject' => 'PD: My: Gołblahblah'], $headers->toArray());
    }

    /**
     * @see https://github.com/zendframework/zend-mail/pull/61
     */
    #[Test]
    public function reencodesDecodedValueAsUtf8(): void
    {
        $headers = Headers::fromString('Subject: =?ISO-8859-2?Q?PD=3A_My=3A_Go=B3?= =?ISO-8859-2?Q?blahblah?=');

        static::assertSame('Subject: =?UTF-8?Q?PD:=20My:=20Go=C5=82blahblah?=', $headers->get('Subject')?->toString());
    }

    /**
     * A tab continuation must not break the value when the block is parsed.
     *
     * @see https://github.com/zendframework/zend-mail/pull/187
     */
    #[Test]
    public function unfoldsTabContinuationLine(): void
    {
        $headers = Headers::fromString(
            "DKIM-Signature: v=1; a=rsa-sha25; c=relaxed/simple; d=example.org; h=\r\n"
                . "\tcontent-language:content-type:content-type:in-reply-to",
        );

        static::assertSame(
            'v=1; a=rsa-sha25; c=relaxed/simple; d=example.org; h= content-language:content-type:content-type:in-reply-to',
            $headers->get('DKIM-Signature')?->getFieldValue(),
        );
    }

    #[Test]
    public function writesUtf8DomainAsPunycode(): void
    {
        $headers = new Headers(new Header\To((new AddressList())->with('local-part@ä-umlaut.de')));

        static::assertSame("To: local-part@xn---umlaut-4wa.de\r\n", $headers->toString());
    }

    /**
     * A ">" inside a quoted display name is part of the name.
     *
     * @see https://github.com/zendframework/zend-mail/issues/127
     */
    #[Test]
    public function decodesQuotedDisplayNameContainingAngleBracket(): void
    {
        $headers = Headers::fromString('To: "=?UTF-8?Q?=C3=B5lu?= <bar" <foo.bar@test.com>');

        static::assertSame(['To' => '"õlu <bar" <foo.bar@test.com>'], $headers->toArray());
    }

    /**
     * @see https://github.com/zendframework/zend-mail/issues/127
     */
    #[Test]
    public function encodesQuotedDisplayNameContainingAngleBracket(): void
    {
        $headers = Headers::fromString('To: "=?UTF-8?Q?=C3=B5lu?= <bar" <foo.bar@test.com>');

        static::assertSame('To: =?UTF-8?Q?=C3=B5lu=20=3Cbar?= <foo.bar@test.com>', $headers->get('To')?->toString());
    }

    #[Test]
    #[Group('issue-175')]
    public function arrayOfAsciiAddressHeaderNeedsNoIntlConstants(): void
    {
        static::assertSame(['To' => 'foo@example.com'], Headers::fromString('To: foo@example.com')->toArray());
    }

    #[Test]
    #[Group('issue-175')]
    public function stringOfAsciiAddressHeaderNeedsNoIntlConstants(): void
    {
        static::assertSame(
            'To: foo@example.com' . Headers::EOL,
            Headers::fromString('To: foo@example.com')->toString(),
        );
    }

    /**
     * @return array<string, array{string, array<string, string|list<string>>}>
     */
    public static function headerBlockProvider(): array
    {
        return [
            'single header'                => ['Fake: foo-bar', ['Fake' => 'foo-bar']],
            'missing whitespace'           => ['Fake:foo-bar', ['Fake' => 'foo-bar']],
            'continuation line'            => ["Fake: foo-bar,\r\n      blah-blah", ['Fake' => 'foo-bar, blah-blah']],
            'tab continuation line'        => ["Fake: foo-bar,\r\n\tblah-blah", ['Fake' => 'foo-bar, blah-blah']],
            'trailing header break'        => ["Fake: foo-bar\r\n\r\n", ['Fake' => 'foo-bar']],
            'multiple headers'             => [
                "Fake: foo-bar\r\nAnother-Fake: boo-baz",
                ['Fake' => 'foo-bar', 'Another-Fake' => 'boo-baz'],
            ],
            'same-named headers'           => ["Foo: one\r\nFoo: two", ['Foo' => ['one', 'two']]],
            'empty block'                  => ['', []],
            'whitespace-only line ignored' => ["Foo: bar\r\n   \r\nBaz: baz", ['Foo' => 'bar', 'Baz' => 'baz']],
        ];
    }

    /**
     * @return array<string, array{string, class-string, string, string}>
     */
    public static function rawUtf8HeaderProvider(): array
    {
        return [
            'Subject' => ['Subject: Grüße', Header\Subject::class, 'Grüße', 'Subject: =?UTF-8?Q?Gr=C3=BC=C3=9Fe?='],
            'From'    => [
                'From: Jösé <jose@example.com>',
                Header\From::class,
                'Jösé <jose@example.com>',
                'From: =?UTF-8?Q?J=C3=B6s=C3=A9?= <jose@example.com>',
            ],
            'Sender'  => [
                'Sender: Zoë <zoe@example.com>',
                Header\Sender::class,
                'Zoë <zoe@example.com>',
                'Sender: =?UTF-8?Q?Zo=C3=AB?= <zoe@example.com>',
            ],
            'generic' => ['X: bär', GenericHeader::class, 'bär', 'X: =?UTF-8?Q?b=C3=A4r?='],
            'folded'  => ["X: b\r\n är", GenericHeader::class, 'b är', 'X: =?UTF-8?Q?b=20=C3=A4r?='],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRawHeaderProvider(): array
    {
        return [
            'NUL'        => ["Subject: a\x00b\r\n"],
            'escape'     => ["Subject: a\x1Bb\r\n"],
            'DEL'        => ["Subject: a\x7Fb\r\n"],
            'C1 control' => ["Subject: a\xC2\x9Bb\r\n"],
        ];
    }

    /**
     * @return array<string, array{string, string, class-string<Header\HeaderInterface>}>
     */
    public static function knownHeaderProvider(): array
    {
        return [
            'subject'  => ['Subject: Hello', 'subject', Header\Subject::class],
            'to'       => ['To: foo@example.com', 'to', Header\To::class],
            'received' => ['Received: from framework', 'received', Header\Received::class],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function lineNotMatchingHeaderFormatProvider(): array
    {
        return [
            'no colon'              => [
                "Fake = foo-bar\r\n\r\n",
                'Line "Fake = foo-bar" does not match header format!',
            ],
            'continuation first'    => [' leading: x', 'Line " leading: x" does not match header format!'],
            'space in name'         => ['Fake Name: x', 'Line "Fake Name: x" does not match header format!'],
            'text after blank line' => ["Foo: bar\r\n\r\nBody", 'Line "Body" does not match header format!'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedBlockProvider(): array
    {
        return [
            'header after two blank lines' => ["Fake: foo-bar\r\n\r\n\r\n\r\nAnother-Fake: boo-baz"],
            'three blank lines'            => ["Fake: foo-bar\r\n\r\n\r\n"],
            'text after two blank lines'   => ["Fake: foo-bar\r\n\r\n\r\n evil"],
        ];
    }

    /**
     * @return array<string, array{iterable<int|string, string|array{string, string}>}>
     */
    public static function injectedIterableProvider(): array
    {
        return [
            'complete line'  => [["Fake: foo-bar\r\n\r\nevilContent"]],
            'name and value' => [['Fake' => "foo-bar\r\n\r\nevilContent"]],
            'pair'           => [[['Fake', "foo-bar\r\n\r\nevilContent"]]],
            'bare line feed' => [['Fake' => "foo-bar\nBcc: evil@example.com"]],
        ];
    }

    #[DataProvider('wireTextProvider')]
    #[Test]
    public function writesParsedHeadersWithTheTextTheyWereReadWith(string $block, string $eol, string $expected): void
    {
        static::assertSame($expected, Headers::fromString($block, $eol)->toString());
    }

    #[DataProvider('rewrittenTextProvider')]
    #[Test]
    public function writesParsedHeaderFromItsValueWhenItsTextCannotBeKept(string $block, string $eol): void
    {
        static::assertSame("Subject: a b\r\n", Headers::fromString($block, $eol)->toString());
    }

    #[Test]
    public function writesReplacedHeaderFromItsValue(): void
    {
        $headers = Headers::fromString("subject:   =?UTF-8?Q?Gr=C3=BC=C3=9Fe?=\r\n")->with(new Header\Subject('Grüße'));

        static::assertSame("Subject: =?UTF-8?Q?Gr=C3=BC=C3=9Fe?=\r\n", $headers->toString());
    }

    #[Test]
    public function keepsTextOfHeadersLeftInPlaceByWith(): void
    {
        $headers = Headers::fromString("subject:  Hello\r\nX-Id:   1\r\n")->with(new GenericHeader('X-Id', '2'));

        static::assertSame("subject:  Hello\r\nX-Id: 2\r\n", $headers->toString());
    }

    #[Test]
    public function keepsTextOfParsedHeaderSetAgainWithWith(): void
    {
        $parsed = Headers::fromString("subject:  Hello\r\n");
        $header = $parsed->get('Subject');
        static::assertNotNull($header);

        static::assertSame("subject:  Hello\r\n", $parsed->with($header)->toString());
    }

    #[Test]
    public function keepsTextOfHeadersLeftByWithout(): void
    {
        $headers = Headers::fromString("subject:  Hello\r\nX-Id:   1\r\n")->without('X-Id');

        static::assertSame("subject:  Hello\r\n", $headers->toString());
    }

    #[Test]
    public function keepsTextOfHeadersBeforeOneAddedWithWithAdded(): void
    {
        $headers = Headers::fromString("received:  from a\r\n")->withAdded(new Header\Received('from b'));

        static::assertSame("received:  from a\r\nReceived: from b\r\n", $headers->toString());
    }

    #[Test]
    public function writesHeadersBuiltFromIterableFromTheirValues(): void
    {
        static::assertSame("Subject: Hello\r\n", Headers::fromIterable(['subject:   Hello'])->toString());
    }

    #[Test]
    public function keepsUnfoldedValueOfParsedHeader(): void
    {
        $headers = Headers::fromString("Subject: Hello\r\n\tworld\r\n");

        static::assertSame('Hello world', $headers->get('Subject')?->getFieldValue());
    }

    /**
     * Resource exhaustion: a header block is read only up to a size limit.
     */
    #[Test]
    public function rejectsHeaderBlockLargerThanTheLimit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A header block may be at most 1048576 bytes');

        Headers::fromString('X-Big: ' . str_repeat('a', times: HeaderLines::MAX_BLOCK_BYTES));
    }

    #[Test]
    public function readsHeaderBlockOfExactlyTheLimit(): void
    {
        $block = 'X-Big: ' . str_repeat('a', times: HeaderLines::MAX_BLOCK_BYTES - 7);

        static::assertCount(1, Headers::fromString($block));
    }

    /**
     * Resource exhaustion: a header block holds a limited number of headers.
     */
    #[Test]
    public function rejectsMoreHeadersThanTheLimit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A header block may hold at most 1000 headers');

        Headers::fromString(str_repeat("X-A: 1\r\n", times: HeaderBlock::MAX_HEADERS + 1));
    }

    #[Test]
    public function readsExactlyTheLimitOfHeaders(): void
    {
        static::assertCount(
            HeaderBlock::MAX_HEADERS,
            Headers::fromString(str_repeat("X-A: 1\r\n", times: HeaderBlock::MAX_HEADERS)),
        );
    }

    /**
     * RFC 5322 line limit: a line read longer than 998 octets is not written back as it was.
     */
    #[Test]
    public function writesOverlongReadLineFromItsValue(): void
    {
        $value = str_repeat('a', times: 993);

        static::assertSame("X-A: {$value}\r\n", Headers::fromString("x-a:  {$value}")->toString());
    }

    #[Test]
    public function keepsReadLineOfExactlyTheLineLimit(): void
    {
        $line = 'x-a:  ' . str_repeat('a', times: 992);

        static::assertSame("{$line}\r\n", Headers::fromString($line)->toString());
    }

    #[Test]
    public function ignoresWhitespaceAfterTheBlankLines(): void
    {
        static::assertCount(1, Headers::fromString("Subject: a\r\n\r\n\r\n  "));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function wireTextProvider(): array
    {
        return [
            'encoded word'          => [
                "Subject: =?ISO-8859-2?Q?PD=3A_My=3A_Go=B3?= =?ISO-8859-2?Q?blahblah?=\r\n",
                "\r\n",
                "Subject: =?ISO-8859-2?Q?PD=3A_My=3A_Go=B3?= =?ISO-8859-2?Q?blahblah?=\r\n",
            ],
            'folding'               => [
                "DKIM-Signature: v=1; a=rsa-sha256;\r\n\tc=relaxed/simple; d=example.org;\r\n h=from:to\r\n",
                "\r\n",
                "DKIM-Signature: v=1; a=rsa-sha256;\r\n\tc=relaxed/simple; d=example.org;\r\n h=from:to\r\n",
            ],
            'name case and spacing' => ["subject:Hello  \r\n", "\r\n", "subject:Hello  \r\n"],
            'line feeds'            => [
                "Subject: a\n b\nTo: x@example.com\n",
                "\n",
                "Subject: a\r\n b\r\nTo: x@example.com\r\n",
            ],
            'crlf read as lf'       => ["Subject: a\r\n b\r\n\r\n", "\n", "Subject: a\r\n b\r\n"],
            'blank line first'      => ["  \r\nSubject: a\r\n", "\r\n", "Subject: a\r\n"],
            'empty group'           => ["To: undisclosed-recipients:;\r\n", "\r\n", "To: undisclosed-recipients:;\r\n"],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rewrittenTextProvider(): array
    {
        return [
            'whitespace-only continuation' => ["Subject: a\r\n \r\n b\r\n", "\r\n"],
            'stray carriage return'        => ["Subject: a\r\r\n b\r\n", "\r\n"],
        ];
    }

    /**
     * @return array<string, array{iterable<int|string, Header\HeaderInterface|string|array{string, string}>}>
     */
    public static function iterableProvider(): array
    {
        return [
            'header objects'   => [[new GenericHeader('Foo', 'bar'), new GenericHeader('Baz', 'baz')]],
            'complete lines'   => [['Foo: bar', 'Baz: baz']],
            'name value pairs' => [[['Foo', 'bar'], ['Baz', 'baz']]],
            'names as keys'    => [['Foo' => 'bar', 'Baz' => 'baz']],
            'traversable'      => [new ArrayIterator(['Foo' => 'bar', 'Baz' => 'baz'])],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function equivalentNameProvider(): array
    {
        return [
            'exact'      => ['Content-Type'],
            'lower case' => ['content-type'],
            'upper case' => ['CONTENT-TYPE'],
            'underscore' => ['content_type'],
            'space'      => ['content type'],
            'full stop'  => ['content.type'],
            'no hyphen'  => ['contenttype'],
        ];
    }
}
