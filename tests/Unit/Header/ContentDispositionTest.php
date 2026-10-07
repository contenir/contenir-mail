<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\ContentDisposition;
use Contenir\Mail\Header\Exception\InvalidArgumentException;
use Contenir\Mail\Header\HeaderParameters;
use Contenir\Mail\Header\ParameterContinuation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;
use function str_repeat;

#[CoversClass(ContentDisposition::class)]
#[CoversClass(HeaderParameters::class)]
#[CoversClass(ParameterContinuation::class)]
#[Group('unit')]
final class ContentDispositionTest extends TestCase
{
    private const string LONG_FILENAME =
        'this-file-name-is-so-long-that-it-does-not-even-fit-on-a-whole-line-by-itself'
            . '-so-we-need-to-split-it-with-value-continuation.txt';

    private const string LONG_UTF8_FILENAME =
        'this-file-name-is-so-long-that-it-does-not-even-fit-on-a-whole-line-by-itself'
            . '-so-we-need-to-split-it-with-value-continuation.also-UTF-8-characters-hērē.txt';

    #[Test]
    public function isInlineByDefault(): void
    {
        static::assertSame('inline', (new ContentDisposition())->getDisposition());
    }

    #[Test]
    public function hasNoParametersByDefault(): void
    {
        static::assertSame([], (new ContentDisposition())->getParameters());
    }

    #[Test]
    public function lowerCasesDisposition(): void
    {
        static::assertSame('attachment', (new ContentDisposition('ATTACHMENT'))->getDisposition());
    }

    #[Test]
    public function lowerCasesParameterNames(): void
    {
        static::assertSame(
            ['filename' => 'a.txt'],
            (new ContentDisposition('attachment', ['FileName' => 'a.txt']))->getParameters(),
        );
    }

    #[Test]
    public function writesFieldNameContentDisposition(): void
    {
        static::assertSame('Content-Disposition', (new ContentDisposition())->getFieldName());
    }

    #[Test]
    public function ignoresTrailingSemicolon(): void
    {
        $header = ContentDisposition::fromString('Content-Disposition: attachment; filename="test-case.txt";');

        static::assertSame(['filename' => 'test-case.txt'], $header->getParameters());
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('literalProvider')]
    #[Test]
    public function keepsSpecialsInsideQuotedParameter(string $fieldValue, array $expected): void
    {
        static::assertSame(
            $expected,
            ContentDisposition::fromString("Content-Disposition: {$fieldValue}")->getParameters(),
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('headerProvider')]
    #[Test]
    public function parsesDisposition(
        string $disposition,
        array $parameters,
        string $fieldValue,
        string $headerLine,
    ): void {
        static::assertSame($disposition, ContentDisposition::fromString($headerLine)->getDisposition());
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('headerProvider')]
    #[Test]
    public function parsesParameters(
        string $disposition,
        array $parameters,
        string $fieldValue,
        string $headerLine,
    ): void {
        static::assertSame($parameters, ContentDisposition::fromString($headerLine)->getParameters());
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('headerProvider')]
    #[Test]
    public function writesDecodedFieldValue(string $disposition, array $parameters, string $fieldValue): void
    {
        static::assertSame($fieldValue, (new ContentDisposition($disposition, $parameters))->getFieldValue());
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('headerProvider')]
    #[Test]
    public function writesHeaderLine(
        string $disposition,
        array $parameters,
        string $fieldValue,
        string $headerLine,
    ): void {
        static::assertSame($headerLine, (new ContentDisposition($disposition, $parameters))->toString());
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('headerProvider')]
    #[Test]
    public function roundTripsHeaderLine(
        string $disposition,
        array $parameters,
        string $fieldValue,
        string $headerLine,
    ): void {
        static::assertSame($headerLine, ContentDisposition::fromString($headerLine)->toString());
    }

    #[DataProvider('invalidHeaderLinesProvider')]
    #[Test]
    public function fromStringRejectsInvalidHeaderLine(string $headerLine, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ContentDisposition::fromString($headerLine);
    }

    #[Test]
    public function fromStringReadsFoldedDisposition(): void
    {
        $header = ContentDisposition::fromString("Content-Disposition: attachment;\r\n level=1");

        static::assertSame('attachment', $header->getDisposition());
    }

    #[Test]
    public function fromStringReadsFoldedParameters(): void
    {
        $header = ContentDisposition::fromString("Content-Disposition: attachment;\r\n level=1");

        static::assertSame(['level' => '1'], $header->getParameters());
    }

    /**
     * The section number is optional when the value is not continued (RFC 2231).
     *
     * @param array<string, string> $parameters
     */
    #[DataProvider('parameterWrappingProvider')]
    #[Test]
    public function joinsRfc2231Continuations(string $headerLine, array $parameters): void
    {
        static::assertSame($parameters, ContentDisposition::fromString($headerLine)->getParameters());
    }

    #[Test]
    public function rejectsNonNumericContinuationSection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Invalid header line for Content-Disposition string - count expected to be numeric, got "a"',
        );

        ContentDisposition::fromString(
            "Content-Disposition: attachment;filename*0*=UTF-8''%76%C3%A4%6C%6A%61%70%C3%A4%C3%A4%73%75%2D%65%69%2D%6F;"
                . 'filename*a*=%6C%65%2E%6A%70%67',
        );
    }

    #[Test]
    public function rejectsNonNumericSectionWithoutExtendedMarker(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Invalid header line for Content-Disposition string - count expected to be numeric, got "b"',
        );

        ContentDisposition::fromString('Content-Disposition: attachment; filename*b="x"');
    }

    #[DataProvider('invalidParametersProvider')]
    #[Test]
    public function rejectsInvalidParameterName(string $name, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ContentDisposition('attachment', [$name => 'value']);
    }

    #[DataProvider('invalidParametersProvider')]
    #[Test]
    public function withParameterRejectsInvalidParameterName(string $name, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new ContentDisposition('attachment'))->withParameter($name, value: 'value');
    }

    #[DataProvider('getParameterProvider')]
    #[Test]
    public function getParameterReadsParsedValue(string $headerLine, string $name, ?string $expected): void
    {
        static::assertSame($expected, ContentDisposition::fromString($headerLine)->getParameter($name));
    }

    #[Test]
    public function withDispositionChangesDisposition(): void
    {
        static::assertSame(
            'attachment',
            (new ContentDisposition())->withDisposition('Attachment')
                ->getDisposition(),
        );
    }

    #[Test]
    public function withDispositionKeepsParameters(): void
    {
        $header = (new ContentDisposition('inline', ['filename' => 'a.txt']))->withDisposition('attachment');

        static::assertSame(['filename' => 'a.txt'], $header->getParameters());
    }

    #[Test]
    public function withDispositionLeavesOriginalUnchanged(): void
    {
        $header = new ContentDisposition();
        $header->withDisposition('attachment');

        static::assertSame('inline', $header->getDisposition());
    }

    #[Test]
    public function withParameterAddsParameter(): void
    {
        $header = (new ContentDisposition('attachment', ['filename' => 'a.txt']))->withParameter('Size', value: '10');

        static::assertSame(['filename' => 'a.txt', 'size' => '10'], $header->getParameters());
    }

    #[Test]
    public function withParameterReplacesParameterWhateverItsCase(): void
    {
        $header = (new ContentDisposition('attachment', ['filename' => 'a.txt']))->withParameter(
            'FILENAME',
            value: 'b.txt',
        );

        static::assertSame(['filename' => 'b.txt'], $header->getParameters());
    }

    #[Test]
    public function withParameterLeavesOriginalUnchanged(): void
    {
        $header = new ContentDisposition();
        $header->withParameter('name', value: 'value');

        static::assertSame([], $header->getParameters());
    }

    #[Test]
    public function withoutParameterRemovesParameter(): void
    {
        $header = (new ContentDisposition('inline', ['name' => 'value']))->withoutParameter('Name');

        static::assertSame([], $header->getParameters());
    }

    #[Test]
    public function withoutParameterIgnoresMissingParameter(): void
    {
        $header = (new ContentDisposition('inline', ['name' => 'value']))->withoutParameter('no-such-parameter');

        static::assertSame(['name' => 'value'], $header->getParameters());
    }

    #[Test]
    public function withoutParameterLeavesOriginalUnchanged(): void
    {
        $header = new ContentDisposition('inline', ['name' => 'value']);
        $header->withoutParameter('name');

        static::assertSame(['name' => 'value'], $header->getParameters());
    }

    #[DataProvider('unconventionalHeaderLinesProvider')]
    #[Test]
    public function fromStringAcceptsUnconventionalNames(string $headerLine): void
    {
        static::assertSame('inline', ContentDisposition::fromString($headerLine)->getFieldValue());
    }

    #[DataProvider('unconventionalHeaderLinesProvider')]
    #[Test]
    public function fromStringWritesCanonicalName(string $headerLine): void
    {
        static::assertSame('Content-Disposition', ContentDisposition::fromString($headerLine)->getFieldName());
    }

    #[Test]
    public function splitsLongValueIntoContinuations(): void
    {
        static::assertSame(
            ";\r\n filename*0=\"this-file-name-is-so-long-that-it-does-not-even-fit-on-a-whole-\";"
                . "\r\n filename*1=\"line-by-itself-so-we-need-to-split-it-with-value-continuation.t\";"
                . "\r\n filename*2=\"xt\"",
            ParameterContinuation::split('filename', self::LONG_FILENAME),
        );
    }

    #[Test]
    public function splitsEmptyValueIntoNothing(): void
    {
        static::assertSame('', ParameterContinuation::split('filename', value: ''));
    }

    #[Test]
    public function joinsSectionsWrittenOutOfOrder(): void
    {
        static::assertSame(
            ['filename' => 'first-second'],
            ParameterContinuation::join([
                ['filename*1', 'second'],
                ['filename*0', 'first-'],
            ], headerLine: ''),
        );
    }

    #[Test]
    public function passesUncontinuedParametersThrough(): void
    {
        static::assertSame(
            ['size' => '10'],
            ParameterContinuation::join([['size', '10']], headerLine: ''),
        );
    }

    #[Test]
    public function splitThenJoinRestoresLongValue(): void
    {
        $header = ContentDisposition::fromString(
            'Content-Disposition: attachment' . ParameterContinuation::split('filename', self::LONG_FILENAME),
        );

        static::assertSame(self::LONG_FILENAME, $header->getParameter('filename'));
    }

    #[Test]
    public function encodedSplitThenJoinRestoresLongUtf8Value(): void
    {
        $header = ContentDisposition::fromString(
            'Content-Disposition: attachment'
                . ParameterContinuation::splitEncoded('filename', self::LONG_UTF8_FILENAME),
        );

        static::assertSame(self::LONG_UTF8_FILENAME, $header->getParameter('filename'));
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function literalProvider(): array
    {
        return [
            'semicolon'     => ['attachment; filename="foo; bar.txt"', ['filename' => 'foo; bar.txt']],
            'ampersand'     => ['attachment; filename="foo&bar.txt"', ['filename' => 'foo&bar.txt']],
            'no parameters' => ['inline', []],
        ];
    }

    /**
     * @return array<string, array{string, array<string, string>, string, string}>
     */
    public static function headerProvider(): array
    {
        $continuationFieldValue =
            "attachment;\r\n filename*0=\"this-file-name-is-so-long-that-it-does-not-even-fit-on-a-whole-\";"
            . "\r\n filename*1=\"line-by-itself-so-we-need-to-split-it-with-value-continuation.t\";\r\n filename*2=\"xt\"";

        $multibyteFilename             = '办公.xlsx';
        $multibyteContinuationFilename = '办公用品预约Apply for office supplies online.xlsx';

        return [
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
                "attachment;\r\n filename=\"this-test-filename-is-long-enough-to-flow-to-two-lines.txt\"",
                "Content-Disposition: attachment;\r\n filename=\"this-test-filename-is-long-enough-to-flow-to-two-lines.txt\"",
            ],
            'encoded characters'           => [
                'attachment',
                ['filename' => 'Ó'],
                'attachment; filename="Ó"',
                'Content-Disposition: attachment; filename="=?UTF-8?Q?=C3=93?="',
            ],
            'value continuation'           => [
                'attachment',
                ['filename' => self::LONG_FILENAME],
                $continuationFieldValue,
                "Content-Disposition: {$continuationFieldValue}",
            ],
            'multiple simple parameters'   => [
                'inline',
                ['one' => '1', 'two' => '2'],
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
                "attachment; filename=\"nōtes-from-our-mēēting.rtf\";\r\n meeting-chair=\"Simon\";"
                    . " attendees=\"Alice, Bob, Charlie\";\r\n appologies=\"Mallory\"",
                "Content-Disposition: attachment;\r\n filename=\"=?UTF-8?Q?n=C5=8Dtes-from-our-m=C4=93=C4=93ting.rtf?=\";"
                    . "\r\n meeting-chair=\"Simon\"; attendees=\"Alice, Bob, Charlie\";\r\n appologies=\"Mallory\"",
            ],
            'UTF-8 continuation'           => [
                'attachment',
                ['filename' => self::LONG_UTF8_FILENAME],
                "attachment;\r\n filename*0=\"this-file-name-is-so-long-that-it-does-not-even-fit-on-a-whole-\";"
                    . "\r\n filename*1=\"line-by-itself-so-we-need-to-split-it-with-value-continuation.a\";"
                    . "\r\n filename*2=\"lso-UTF-8-characters-hērē.txt\"",
                "Content-Disposition: attachment;\r\n filename*0=\"=?UTF-8?Q?this-file-name-is-so-long-that-it-does-not-even-fit?=\";"
                    . "\r\n filename*1=\"=?UTF-8?Q?-on-a-whole-line-by-itself-so-we-need-to-split-it-w?=\";"
                    . "\r\n filename*2=\"=?UTF-8?Q?ith-value-continuation.also-UTF-8-characters-h?=\";"
                    . "\r\n filename*3=\"=?UTF-8?Q?=C4=93r=C4=93.txt?=\"",
            ],
            'UTF-8 multibyte'              => [
                'attachment',
                ['filename' => $multibyteFilename],
                "attachment; filename=\"{$multibyteFilename}\"",
                "Content-Disposition: attachment;\r\n filename=\"=?UTF-8?Q?=E5=8A=9E=E5=85=AC.xlsx?=\"",
            ],
            'UTF-8 multibyte continuation' => [
                'attachment',
                ['filename' => $multibyteContinuationFilename],
                "attachment;\r\n filename=\"{$multibyteContinuationFilename}\"",
                "Content-Disposition: attachment;\r\n filename*0=\"=?UTF-8?Q?=E5=8A=9E=E5=85=AC=E7=94=A8=E5=93=81=E9=A2=84?=\";"
                    . "\r\n filename*1=\"=?UTF-8?Q?=E7=BA=A6Apply=20for=20office=20supplies=20online.x?=\";"
                    . "\r\n filename*2=\"=?UTF-8?Q?lsx?=\"",
            ],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidParametersProvider(): array
    {
        return [
            // @group ZF2015-04
            'name with CRLF' => ["b\r\na\rr\n", 'Invalid content-disposition parameter name detected'],
            'name too long'  => [
                'this-parameter-name-is-so-long-that-it-leaves-no-room-for-any-value-to-be-set',
                'Invalid content-disposition parameter name detected (too long)',
            ],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidHeaderLinesProvider(): array
    {
        $incomplete = "Content-Disposition: attachment;\r\n filename*0=\"first-part\";\r\n filename*2=\"third-part\"";

        return [
            'another header'      => ['Subject: important email', 'Invalid header line for Content-Disposition string'],
            'space before colon'  => ['Content-Disposition' . chr(32) . ': inline', 'Invalid header name detected'],
            'newline'             => ["Content-Disposition: inline;\nlevel=1", 'Invalid header value detected'],
            'cr-lf'               => ["Content-Disposition: inline\r\n;level=1", 'Invalid header value detected'],
            'multiline'           => [
                "Content-Disposition: inline;\r\nlevel=1\r\nq=0.1",
                'Invalid header value detected',
            ],
            'incomplete sequence' => [
                $incomplete,
                "Invalid header line for Content-Disposition string - incomplete continuation; HeaderLine: {$incomplete}",
            ],
        ];
    }

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function getParameterProvider(): array
    {
        return [
            'no such parameter' => ['Content-Disposition: inline', 'no-such-parameter', null],
            'filename'          => [
                'Content-Disposition: attachment; filename="success.txt"',
                'filename',
                'success.txt',
            ],
            'name in any case'  => [
                'Content-Disposition: attachment; filename="success.txt"',
                'FileName',
                'success.txt',
            ],
            'continued value'   => [
                "Content-Disposition: attachment;\r\n filename*0=\"this-file-name-is-so-long-that-it-does-not-even\";"
                    . "\r\n filename*1=\"-fit-on-a-whole-line-by-itself-so-we-need-to-sp\";"
                    . "\r\n filename*2=\"lit-it-with-value-continuation.txt\"",
                'filename',
                self::LONG_FILENAME,
            ],
        ];
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function parameterWrappingProvider(): array
    {
        return [
            'without sequence number'                  => [
                "Content-Disposition: attachment; filename*=UTF-8''%64%61%61%6D%69%2D%6D%C3%B5%72%76%2E%6A%70%67",
                ['filename' => "UTF-8''%64%61%61%6D%69%2D%6D%C3%B5%72%76%2E%6A%70%67"],
            ],
            'two ordered extended sections'            => [
                'Content-Disposition: attachment;'
                    . "filename*0*=UTF-8''%76%C3%A4%6C%6A%61%70%C3%A4%C3%A4%73%75%2D%65%69%2D%6F;"
                    . 'filename*1*=%6C%65%2E%6A%70%67',
                ['filename' => "UTF-8''%76%C3%A4%6C%6A%61%70%C3%A4%C3%A4%73%75%2D%65%69%2D%6F%6C%65%2E%6A%70%67"],
            ],
            'one item without sequence (laminas #111)' => [
                "Content-Disposition: attachment; filename*=utf-8''Capture%20d%E2%80%99e%CC%81cran%202020%2D05%2D13%20a%CC%80%2017.13.47.png",
                ['filename' => "utf-8''Capture%20d%E2%80%99e%CC%81cran%202020%2D05%2D13%20a%CC%80%2017.13.47.png"],
            ],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unconventionalHeaderLinesProvider(): array
    {
        return [
            'contentdisposition'  => ['ContentDisposition: inline'],
            'content_disposition' => ['Content_Disposition: inline'],
            'lower case'          => ['content-disposition: inline'],
        ];
    }

    #[Test]
    public function acceptsParameterNameJustShortOfTheLineLimit(): void
    {
        $name = str_repeat('n', times: 70);

        static::assertSame('v', (new ContentDisposition('attachment', [$name => 'v']))->getParameter($name));
    }

    #[Test]
    public function rejectsParameterNameThatFillsTheLineLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid content-disposition parameter name detected (too long)');

        new ContentDisposition('attachment', [str_repeat('n', times: 71) => 'v']);
    }

    #[Test]
    public function trimsDispositionBeforeParameters(): void
    {
        static::assertSame(
            'attachment',
            ContentDisposition::fromString('Content-Disposition: attachment ; filename="a"')->getDisposition(),
        );
    }

    #[Test]
    public function splitsLongParameterNameIntoOneCharacterSectionsRatherThanStalling(): void
    {
        $name = str_repeat('n', times: 60);

        static::assertSame(
            "Content-Disposition: attachment;\r\n {$name}*0=\"=?UTF-8?Q?=C3=A9?=\";\r\n {$name}*1=\"=?UTF-8?Q?=C3=A9?=\"",
            (new ContentDisposition('attachment', [$name => 'éé']))->toString(),
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('lineLimitProvider')]
    #[Test]
    public function foldsParametersAtTheLineLimit(array $parameters, string $expected): void
    {
        static::assertSame($expected, (new ContentDisposition('attachment', $parameters))->toString());
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function lineLimitProvider(): array
    {
        $v39 = str_repeat('v', times: 39);
        $v40 = str_repeat('v', times: 40);
        $w25 = str_repeat('w', times: 25);
        $w26 = str_repeat('w', times: 26);
        $f64 = str_repeat('f', times: 64);
        $f65 = str_repeat('f', times: 65);

        return [
            'fills the first line exactly'            => [
                ['a' => $v39],
                "Content-Disposition: attachment; a=\"{$v39}\"",
            ],
            'one past the first line'                 => [
                ['a' => $v40],
                "Content-Disposition: attachment;\r\n a=\"{$v40}\"",
            ],
            'fills a folded line exactly'             => [
                ['a' => $v40, 'b' => $w25],
                "Content-Disposition: attachment;\r\n a=\"{$v40}\"; b=\"{$w25}\"",
            ],
            'one past a folded line'                  => [
                ['a' => $v40, 'b' => $w26],
                "Content-Disposition: attachment;\r\n a=\"{$v40}\";\r\n b=\"{$w26}\"",
            ],
            'longest parameter that is not continued' => [
                ['filename' => $f64],
                "Content-Disposition: attachment;\r\n filename=\"{$f64}\"",
            ],
            'shortest parameter that is continued'    => [
                ['filename' => $f65],
                "Content-Disposition: attachment;\r\n filename*0=\""
                    . str_repeat('f', times: 63)
                    . "\";\r\n filename*1=\"ff\"",
            ],
        ];
    }
}
