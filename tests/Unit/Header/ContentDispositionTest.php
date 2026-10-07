<?php

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\ContentDisposition;
use Contenir\Mail\Header\Exception\InvalidArgumentException;
use Contenir\Mail\Header\HeaderInterface;
use Contenir\Mail\Header\UnstructuredInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Contenir\Mail\Header\ContentDisposition::class)]
class ContentDispositionTest extends TestCase
{
    #[Test]
    public function implementsHeaderInterface(): void
    {
        $header = new ContentDisposition();

        static::assertInstanceOf(UnstructuredInterface::class, $header);
        static::assertInstanceOf(HeaderInterface::class, $header);
    }

    #[Test]
    public function trailingSemiColonFromString(): void
    {
        $contentTypeHeader = ContentDisposition::fromString(
            'Content-Disposition: attachment; filename="test-case.txt";',
        );
        $params = $contentTypeHeader->getParameters();
        static::assertEquals(['filename' => 'test-case.txt'], $params);
    }

    public static function getLiteralData(): array
    {
        return [
            [
                ['filename' => 'foo; bar.txt'],
                'attachment; filename="foo; bar.txt"',
            ],
            [
                ['filename' => 'foo&bar.txt'],
                'attachment; filename="foo&bar.txt"',
            ],
            [
                [],
                'inline',
            ],
        ];
    }

    #[Test]
    #[DataProvider('getLiteralData')]
    public function handlesLiterals(array $expected, string $header): void
    {
        $header = ContentDisposition::fromString('Content-Disposition: ' . $header);
        static::assertEquals($expected, $header->getParameters());
    }

    #[Test]
    #[DataProvider('setDispositionProvider')]
    public function fromString(
        string $disposition,
        array $parameters,
        string $fieldValue,
        string $expectedToString,
    ): void {
        $header = ContentDisposition::fromString($expectedToString);

        static::assertInstanceOf(ContentDisposition::class, $header);
        static::assertEquals('Content-Disposition', $header->getFieldName(), 'getFieldName() value not match');
        static::assertEquals($disposition, $header->getDisposition(), 'getDisposition() value not match');
        static::assertEquals($fieldValue, $header->getFieldValue(), 'getFieldValue() value not match');
        static::assertEquals($parameters, $header->getParameters(), 'getParameters() value not match');
        static::assertEquals($expectedToString, $header->toString(), 'toString() value not match');
    }

    #[Test]
    #[DataProvider('setDispositionProvider')]
    public function setDisposition(
        string $disposition,
        array $parameters,
        string $fieldValue,
        string $expectedToString,
    ): void {
        $header = new ContentDisposition();

        $header->setDisposition($disposition);
        foreach ($parameters as $name => $value) {
            $header->setParameter($name, $value);
        }

        static::assertEquals('Content-Disposition', $header->getFieldName(), 'getFieldName() value not match');
        static::assertEquals($disposition, $header->getDisposition(), 'getDisposition() value not match');
        static::assertEquals($fieldValue, $header->getFieldValue(), 'getFieldValue() value not match');
        static::assertEquals($parameters, $header->getParameters(), 'getParameters() value not match');
        static::assertEquals($expectedToString, $header->toString(), 'toString() value not match');
    }

    #[Test]
    public function getSetEncoding(): void
    {
        $header = new ContentDisposition();

        // default value
        static::assertEquals('ASCII', $header->getEncoding());

        $header->setEncoding('UTF-8');
        static::assertEquals('UTF-8', $header->getEncoding());

        $header->setEncoding('ASCII');
        static::assertEquals('ASCII', $header->getEncoding());
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
        ContentDisposition::fromString($headerLine);
    }

    #[Test]
    public function fromStringHandlesContinuations(): void
    {
        $header = ContentDisposition::fromString("Content-Disposition: attachment;\r\n level=1");
        static::assertEquals('attachment', $header->getDisposition());
        static::assertEquals(['level' => '1'], $header->getParameters());
    }

    /**
     * Should not throw if the optional count is missing
     *
     * @see https://tools.ietf.org/html/rfc2231
     */
    #[Test]
    #[DataProvider('parameterWrappingProvider')]
    public function parameterWrapping(string $input, string $disposition, array $parameters): void
    {
        $header = ContentDisposition::fromString($input);

        static::assertEquals($disposition, $header->getDisposition());
        static::assertEquals($parameters, $header->getParameters());
    }

    #[Test]
    #[DataProvider('parameterWrappingProviderExceptions')]
    public function parameterWrappingExceptions(string $input, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);
        ContentDisposition::fromString($input);
    }

    /**
     * @param class-string $expectedException
     */
    #[Test]
    #[DataProvider('invalidParametersProvider')]
    public function setParameterThrowException(
        string $paramName,
        string $paramValue,
        string $expectedException,
        string $exceptionMessage,
    ): void {
        $header = new ContentDisposition();
        $header->setDisposition('attachment');

        $this->expectException($expectedException);
        $this->expectExceptionMessage($exceptionMessage);
        $header->setParameter($paramName, $paramValue);
    }

    #[Test]
    #[DataProvider('getParameterProvider')]
    public function getParameter(string $fromString, string $paramName, ?string $paramValue): void
    {
        $header = ContentDisposition::fromString($fromString);
        static::assertEquals($paramValue, $header->getParameter($paramName));
    }

    #[Test]
    public function removeParameter(): void
    {
        $header = ContentDisposition::fromString('Content-Disposition: inline');

        static::assertEquals(false, $header->removeParameter('no-such-parameter'));

        $header->setParameter('name', 'value');
        static::assertEquals(true, $header->removeParameter('name'));
    }

    public static function setDispositionProvider(): array
    {
        // @codingStandardsIgnoreStart
        $foldingFieldValue      = "attachment;\r\n filename=\"this-test-filename-is-long-enough-to-flow-to-two-lines.txt\"";
        $foldingHeaderLine      = "Content-Disposition: $foldingFieldValue";
        $continuationFieldValue = "attachment;\r\n filename*0=\"this-file-name-is-so-long-that-it-does-not-even-fit-on-a-whole-\";\r\n filename*1=\"line-by-itself-so-we-need-to-split-it-with-value-continuation.t\";\r\n filename*2=\"xt\"";
        $continuationHeaderLine = "Content-Disposition: $continuationFieldValue";

        $encodedHeaderLine = 'Content-Disposition: attachment; filename="=?UTF-8?Q?=C3=93?="';
        $encodedFieldValue = 'attachment; filename="Ó"';

        $multibyteFilename   = '办公.xlsx';
        $multibyteFieldValue = "attachment;\r\n filename=\"=?UTF-8?Q?=E5=8A=9E=E5=85=AC.xlsx?=\"";
        $multibyteHeaderLine = "Content-Disposition: $multibyteFieldValue";

        $multibyteContinuationFilename   = '办公用品预约Apply for office supplies online.xlsx';
        $multibyteContinuationFieldValue = "attachment;\r\n filename*0=\"=?UTF-8?Q?=E5=8A=9E=E5=85=AC=E7=94=A8=E5=93=81=E9=A2=84?=\";\r\n filename*1=\"=?UTF-8?Q?=E7=BA=A6Apply=20for=20office=20supplies=20online.x?=\";\r\n filename*2=\"=?UTF-8?Q?lsx?=\"";
        $multibyteContinuationHeaderLine = "Content-Disposition: $multibyteContinuationFieldValue";

        return [
            // Description => [$disposition, $parameters, $fieldValue, toString()]
            'inline with no parameters'    => ['inline', [], 'inline', 'Content-Disposition: inline'],
            'parameter on one line'        => [
                'inline',
                ['level' => '1'],
                'inline; level="1"',
                'Content-Disposition: inline; level="1"',
            ],
            'parameter use header folding' => [
                'attachment',
                ['filename' => 'this-test-filename-is-long-enough-to-flow-to-two-lines.txt'],
                $foldingFieldValue,
                $foldingHeaderLine,
            ],
            'encoded characters'           => [
                'attachment',
                ['filename' => 'Ó'],
                $encodedFieldValue,
                $encodedHeaderLine,
            ],
            'value continuation'           => [
                'attachment',
                [
                    'filename' => 'this-file-name-is-so-long-that-it-does-not-even-fit-on-a-whole-line-by-itself-so-we-need-to-split-it-with-value-continuation.txt',
                ],
                $continuationFieldValue,
                $continuationHeaderLine,
            ],
            'multiple simple parameters'   => [
                'inline',
                ['one' => 1, 'two' => 2],
                'inline; one="1"; two="2"',
                'Content-Disposition: inline; one="1"; two="2"',
            ],
            'UTF-8 multi-line'             => [
                'attachment',
                [
                    'filename'      => 'nōtes-from-our-mēēting.rtf',
                    'meeting-chair' => 'Simon',
                    'attendees'     => 'Alice, Bob, Charlie',
                    'appologies'    => 'Mallory',
                ],
                "attachment; filename=\"nōtes-from-our-mēēting.rtf\";\r\n meeting-chair=\"Simon\"; attendees=\"Alice, Bob, Charlie\";\r\n appologies=\"Mallory\"",
                "Content-Disposition: attachment;\r\n filename=\"=?UTF-8?Q?n=C5=8Dtes-from-our-m=C4=93=C4=93ting.rtf?=\";\r\n meeting-chair=\"Simon\"; attendees=\"Alice, Bob, Charlie\";\r\n appologies=\"Mallory\"",
            ],
            'UTF-8 continuation'           => [
                'attachment',
                [
                    'filename' => 'this-file-name-is-so-long-that-it-does-not-even-fit-on-a-whole-line-by-itself-so-we-need-to-split-it-with-value-continuation.also-UTF-8-characters-hērē.txt',
                ],
                "attachment;\r\n filename*0=\"this-file-name-is-so-long-that-it-does-not-even-fit-on-a-whole-\";\r\n filename*1=\"line-by-itself-so-we-need-to-split-it-with-value-continuation.a\";\r\n filename*2=\"lso-UTF-8-characters-hērē.txt\"",
                "Content-Disposition: attachment;\r\n filename*0=\"=?UTF-8?Q?this-file-name-is-so-long-that-it-does-not-even-fit?=\";\r\n filename*1=\"=?UTF-8?Q?-on-a-whole-line-by-itself-so-we-need-to-split-it-w?=\";\r\n filename*2=\"=?UTF-8?Q?ith-value-continuation.also-UTF-8-characters-h?=\";\r\n filename*3=\"=?UTF-8?Q?=C4=93r=C4=93.txt?=\"",
            ],
            'UTF-8 multibyte'              => [
                'attachment',
                ['filename' => $multibyteFilename],
                "attachment; filename=\"$multibyteFilename\"",
                $multibyteHeaderLine,
            ],
            'UTF-8 multibyte continuation' => [
                'attachment',
                ['filename' => $multibyteContinuationFilename],
                "attachment;\r\n filename=\"$multibyteContinuationFilename\"",
                $multibyteContinuationHeaderLine,
            ],
        ];

        // @codingStandardsIgnoreEnd
    }

    public static function invalidParametersProvider(): array
    {
        $invalidArgumentException = InvalidArgumentException::class;

        // @codingStandardsIgnoreStart
        return [
            // Description => [param name, param value, expected exception, exception message contain]
            'invalid name'  => ["b\r\na\rr\n", 'baz', $invalidArgumentException, 'parameter name'],
            'name too long' => [
                'this-parameter-name-is-so-long-that-it-leaves-no-room-for-any-value-to-be-set',
                'too long',
                $invalidArgumentException,
                'too long',
            ],
        ];

        // @codingStandardsIgnoreEnd
    }

    public static function invalidHeaderLinesProvider(): array
    {
        $invalidArgumentException = InvalidArgumentException::class;

        // @codingStandardsIgnoreStart
        return [
            // Description => [header line, expected exception, exception message contain]
            'wrong-header'        => ['Subject: important email', $invalidArgumentException, 'header line'],
            'invalid name'        => [
                'Content-Disposition' . chr(32) . ': inline',
                $invalidArgumentException,
                'header name',
            ],
            'newline'             => [
                "Content-Disposition: inline;\nlevel=1",
                $invalidArgumentException,
                'header value',
            ],
            'cr-lf'               => [
                "Content-Disposition: inline\r\n;level=1",
                $invalidArgumentException,
                'header value',
            ],
            'multiline'           => [
                "Content-Disposition: inline;\r\nlevel=1\r\nq=0.1",
                $invalidArgumentException,
                'header value',
            ],
            'incomplete sequence' => [
                "Content-Disposition: attachment;\r\n filename*0=\"first-part\";\r\n filename*2=\"third-part\"",
                $invalidArgumentException,
                'incomplete continuation',
            ],
        ];

        // @codingStandardsIgnoreEnd
    }

    public static function getParameterProvider(): array
    {
        // @codingStandardsIgnoreStart
        return [
            // Description => [from string, parameter name, parameter Value]
            'no such parameter' => ['Content-Disposition: inline', 'no-such-parameter', null],
            'filename'          => [
                'Content-Disposition: attachment; filename="success.txt"',
                'filename',
                'success.txt',
            ],
            'continued-value'   => [
                "Content-Disposition: attachment;\r\n filename*0=\"this-file-name-is-so-long-that-it-does-not-even\";\r\n filename*1=\"-fit-on-a-whole-line-by-itself-so-we-need-to-sp\";\r\n filename*2=\"lit-it-with-value-continuation.txt\"",
                'filename',
                'this-file-name-is-so-long-that-it-does-not-even-fit-on-a-whole-line-by-itself-so-we-need-to-split-it-with-value-continuation.txt',
            ],
        ];

        // @codingStandardsIgnoreEnd
    }

    public static function parameterWrappingProvider(): iterable
    {
        // @codingStandardsIgnoreStart
        yield 'Without sequence number' => [
            "Content-Disposition: attachment; filename*=UTF-8''%64%61%61%6D%69%2D%6D%C3%B5%72%76%2E%6A%70%67",
            'attachment',
            ['filename' => "UTF-8''%64%61%61%6D%69%2D%6D%C3%B5%72%76%2E%6A%70%67"],
        ];
        yield 'With two ordered items' => [
            'Content-Disposition: attachment;'
                . "filename*0*=UTF-8''%76%C3%A4%6C%6A%61%70%C3%A4%C3%A4%73%75%2D%65%69%2D%6F;"
                . 'filename*1*=%6C%65%2E%6A%70%67',
            'attachment',
            ['filename' => "UTF-8''%76%C3%A4%6C%6A%61%70%C3%A4%C3%A4%73%75%2D%65%69%2D%6F%6C%65%2E%6A%70%67"],
        ];
        yield 'One item, without sequence, https://github.com/laminas/laminas-mail/pull/111' => [
            "Content-Disposition: attachment; filename*=utf-8''Capture%20d%E2%80%99e%CC%81cran%202020%2D05%2D13%20a%CC%80%2017.13.47.png",
            'attachment',
            ['filename' => "utf-8''Capture%20d%E2%80%99e%CC%81cran%202020%2D05%2D13%20a%CC%80%2017.13.47.png"],
        ];

        // @codingStandardsIgnoreEnd
    }

    public static function parameterWrappingProviderExceptions(): iterable
    {
        // @codingStandardsIgnoreStart
        yield 'With non-numeric-sequence' => [
            'Content-Disposition: attachment;'
                . "filename*0*=UTF-8''%76%C3%A4%6C%6A%61%70%C3%A4%C3%A4%73%75%2D%65%69%2D%6F;"
                . 'filename*a*=%6C%65%2E%6A%70%67',
            InvalidArgumentException::class,
            "Invalid header line for Content-Disposition string - count expected to be numeric, got string with value 'a'",
        ];

        // @codingStandardsIgnoreEnd
    }

    public static function unconventionalHeaderLinesProvider(): array
    {
        return [
            // Description => [header line, expected]
            'contentdisposition'  => ['Content-Disposition: inline', 'inline'],
            'content_disposition' => ['Content_Disposition: inline', 'inline'],
        ];
    }

    #[Test]
    #[DataProvider('unconventionalHeaderLinesProvider')]
    public function fromStringHandlesUnconventionalNames(string $headerLine, string $expected): void
    {
        $header = ContentDisposition::fromString($headerLine);
        static::assertInstanceOf(ContentDisposition::class, $header);
        static::assertEquals('Content-Disposition', $header->getFieldName());
        static::assertEquals($expected, $header->getFieldValue());
    }
}
