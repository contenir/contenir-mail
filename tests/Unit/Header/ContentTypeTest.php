<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Header\Exception;
use Contenir\Mail\Header\HeaderInterface;
use Contenir\Mail\Header\UnstructuredInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentType::class)]
class ContentTypeTest extends TestCase
{
    #[Test]
    public function implementsHeaderInterface(): void
    {
        $header = new ContentType();

        static::assertInstanceOf(UnstructuredInterface::class, $header);
        static::assertInstanceOf(HeaderInterface::class, $header);
    }

    #[Test]
    #[Group('6491')]
    public function trailingSemiColonFromString(): void
    {
        $contentTypeHeader = ContentType::fromString(
            'Content-Type: multipart/alternative; boundary="Apple-Mail=_1B852F10-F9C6-463D-AADD-CD503A5428DD";',
        );
        $params = $contentTypeHeader->getParameters();
        static::assertSame(['boundary' => 'Apple-Mail=_1B852F10-F9C6-463D-AADD-CD503A5428DD'], $params);
    }

    #[Test]
    public function extractsExtraInformationWithoutBeingConfusedByTrailingSemicolon(): void
    {
        $header = ContentType::fromString('Content-Type: application/pdf;name="foo.pdf";');
        static::assertSame($header->getParameters(), ['name' => 'foo.pdf']);
    }

    public static function getLiteralData(): array
    {
        return [
            [
                ['name' => 'foo; bar.txt'],
                'text/plain; name="foo; bar.txt"',
            ],
            [
                ['name' => 'foo&bar.txt'],
                'text/plain; name="foo&bar.txt"',
            ],
        ];
    }

    #[Test]
    #[DataProvider('getLiteralData')]
    public function handlesLiterals(array $expected, string $header): void
    {
        $header = ContentType::fromString("Content-Type: {$header}");
        static::assertSame($expected, $header->getParameters());
    }

    #[Test]
    #[DataProvider('setTypeProvider')]
    public function fromString(string $type, array $parameters, string $fieldValue, string $expectedToString): void
    {
        $header = ContentType::fromString($expectedToString);

        static::assertInstanceOf(ContentType::class, $header);
        static::assertSame('Content-Type', $header->getFieldName(), 'getFieldName() value not match');
        static::assertSame($type, $header->getType(), 'getType() value not match');
        static::assertSame($fieldValue, $header->getFieldValue(), 'getFieldValue() value not match');
        static::assertSame($parameters, $header->getParameters(), 'getParameters() value not match');
        static::assertSame($expectedToString, $header->toString(), 'toString() value not match');
    }

    #[Test]
    #[DataProvider('setTypeProvider')]
    public function setType(string $type, array $parameters, string $fieldValue, string $expectedToString): void
    {
        $header = new ContentType();

        $header->setType($type);
        foreach ($parameters as $name => $value) {
            $header->addParameter($name, $value);
        }

        static::assertSame('Content-Type', $header->getFieldName(), 'getFieldName() value not match');
        static::assertSame($type, $header->getType(), 'getType() value not match');
        static::assertSame($fieldValue, $header->getFieldValue(), 'getFieldValue() value not match');
        static::assertSame($parameters, $header->getParameters(), 'getParameters() value not match');
        static::assertSame($expectedToString, $header->toString(), 'toString() value not match');
    }

    /**
     * @param class-string $expectedException
     */
    #[Test]
    #[DataProvider('invalidHeaderLinesProvider')]
    public function fromStringThrowException(
        string $headerLine,
        string $expectedException,
        string $exceptionMessage,
    ): void {
        $this->expectException($expectedException);
        $this->expectExceptionMessage($exceptionMessage);
        ContentType::fromString($headerLine);
    }

    #[Test]
    #[Group('ZF2015-04')]
    public function fromStringHandlesContinuations(): void
    {
        $header = ContentType::fromString("Content-Type: text/html;\r\n level=1");
        static::assertSame('text/html', $header->getType());
        static::assertSame(['level' => '1'], $header->getParameters());
    }

    /**
     * Should not throw if the optional count is missing
     *
     * @see https://tools.ietf.org/html/rfc2231
     */
    #[Test]
    #[DataProvider('parameterWrappingProvider')]
    public function parameterWrapping(string $input, array $parameters): void
    {
        $header = ContentType::fromString($input);

        static::assertSame($parameters, $header->getParameters());
    }

    /**
     * @param class-string $expectedException
     */
    #[Test]
    #[DataProvider('invalidParametersProvider')]
    public function addParameterThrowException(
        string $paramName,
        string $paramValue,
        string $expectedException,
        string $exceptionMessage,
    ): void {
        $header = new ContentType();
        $header->setType('text/html');

        $this->expectException($expectedException);
        $this->expectExceptionMessage($exceptionMessage);
        $header->addParameter($paramName, $paramValue);
    }

    public static function setTypeProvider(): array
    {
        $foldingHeaderLine = "Content-Type: foo/baz;\r\n charset=\"us-ascii\"";
        $foldingFieldValue = "foo/baz;\r\n charset=\"us-ascii\"";

        $encodedHeaderLine = "Content-Type: foo/baz;\r\n name=\"=?UTF-8?Q?=C3=93?=\"";
        $encodedFieldValue = "foo/baz;\r\n name=\"Ó\"";

        // @codingStandardsIgnoreStart
        return [
            // Description => [$type, $parameters, $fieldValue, toString()]
            // @group #2728
            'foo/a.b-c'                    => ['foo/a.b-c', [], 'foo/a.b-c', 'Content-Type: foo/a.b-c'],
            'foo/a+b'                      => ['foo/a+b', [], 'foo/a+b', 'Content-Type: foo/a+b'],
            'foo/baz'                      => ['foo/baz', [], 'foo/baz', 'Content-Type: foo/baz'],
            'parameter use header folding' => [
                'foo/baz',
                ['charset' => 'us-ascii'],
                $foldingFieldValue,
                $foldingHeaderLine,
            ],
            'encoded characters'           => ['foo/baz', ['name' => 'Ó'], $encodedFieldValue, $encodedHeaderLine],
        ];

        // @codingStandardsIgnoreEnd
    }

    public static function invalidParametersProvider(): array
    {
        $invalidArgumentException = Exception\InvalidArgumentException::class;

        // @codingStandardsIgnoreStart
        return [
            // Description => [param name, param value, expected exception, exception message contain]

            // @group ZF2015-04
            'invalid name' => ["b\r\na\rr\n", 'baz', $invalidArgumentException, 'parameter name'],
        ];

        // @codingStandardsIgnoreEnd
    }

    public static function invalidHeaderLinesProvider(): array
    {
        $invalidArgumentException = Exception\InvalidArgumentException::class;

        // @codingStandardsIgnoreStart
        return [
            // Description => [header line, expected exception, exception message contain]

            // @group ZF2015-04
            'invalid name' => ['Content-Type' . chr(32) . ': text/html', $invalidArgumentException, 'header name'],
            'newline'      => ["Content-Type: text/html;\nlevel=1", $invalidArgumentException, 'header value'],
            'cr-lf'        => ["Content-Type: text/html\r\n;level=1", $invalidArgumentException, 'header value'],
            'multiline'    => [
                "Content-Type: text/html;\r\nlevel=1\r\nq=0.1",
                $invalidArgumentException,
                'header value',
            ],
        ];

        // @codingStandardsIgnoreEnd
    }

    #[Test]
    public function fromStringRaisesExceptionOnInvalidHeader(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for Content-Type string');
        ContentType::fromString('Foo: bar');
    }

    #[Test]
    public function defaultEncoding(): void
    {
        $header = new ContentType();
        static::assertSame('ASCII', $header->getEncoding());
    }

    #[Test]
    public function setEncoding(): void
    {
        $header = new ContentType();
        $header->setEncoding('UTF-8');
        static::assertSame('UTF-8', $header->getEncoding());
    }

    #[Test]
    public function setTypeThrowsOnInvalidValue(): void
    {
        $header = new ContentType();
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('setType expects a value in the format "type/subtype"');
        $header->setType('invalid');
    }

    #[Test]
    public function getParameter(): void
    {
        $header = ContentType::fromString('content-type: text/plain; level=top');
        static::assertSame('top', $header->getParameter('level'));
    }

    #[Test]
    public function getParameterWithSpaceTrimmed(): void
    {
        $header = ContentType::fromString('content-type: text/plain; level=top; name="logfile.log";');
        static::assertSame('logfile.log', $header->getParameter('name'));
    }

    #[Test]
    public function getParameterNotExists(): void
    {
        $header = ContentType::fromString('content-type: text/plain');
        static::assertNull($header->getParameter('level'));
    }

    #[Test]
    public function removeParameter(): void
    {
        $header = ContentType::fromString('content-type: text/plain; level=top');
        static::assertTrue($header->removeParameter('level'));
    }

    #[Test]
    public function removeParameterNotExists(): void
    {
        $header = ContentType::fromString('content-type: text/plain');
        static::assertFalse($header->removeParameter('level'));
    }

    public static function parameterWrappingProvider(): iterable
    {
        yield 'Example from RFC2231' => [
            "Content-Type: application/x-stuff; title*=us-ascii'en-us'This%20is%20%2A%2A%2Afun%2A%2A%2A",
            ['title*' => "us-ascii'en-us'This%20is%20%2A%2A%2Afun%2A%2A%2A"],
        ];
    }

    public static function unconventionalHeaderLinesProvider(): array
    {
        return [
            // Description => [header line, expected value]
            'contenttype'  => ['ContentType: text/plain', 'text/plain'],
            'content_type' => ['Content_Type: text/plain', 'text/plain'],
        ];
    }

    #[Test]
    #[DataProvider('unconventionalHeaderLinesProvider')]
    public function fromStringHandlesUnconventionalNames(string $headerLine, string $expected): void
    {
        $header = ContentType::fromString($headerLine);
        static::assertInstanceOf(ContentType::class, $header);
        static::assertSame('Content-Type', $header->getFieldName());
        static::assertSame($expected, $header->getFieldValue());
    }
}
