<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\Exception;
use Contenir\Mail\Header\Subject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function rtrim;
use function str_repeat;

#[CoversClass(Subject::class)]
#[Group('unit')]
final class SubjectTest extends TestCase
{
    #[Test]
    public function foldsLongAsciiSubjectAtSeventyEightCharacters(): void
    {
        $subject = new Subject(str_repeat('foobarblahblahblah baz bat', times: 10));

        static::assertSame(
            "foobarblahblahblah baz batfoobarblahblahblah baz\r\n "
                . "batfoobarblahblahblah baz batfoobarblahblahblah baz batfoobarblahblahblah baz\r\n "
                . "batfoobarblahblahblah baz batfoobarblahblahblah baz batfoobarblahblahblah baz\r\n "
                . 'batfoobarblahblahblah baz batfoobarblahblahblah baz bat',
            $subject->getEncodedFieldValue(),
        );
    }

    #[Test]
    public function keepsLongSubjectUnfoldedInDecodedValue(): void
    {
        $value = str_repeat('foobarblahblahblah baz bat', times: 10);

        static::assertSame($value, (new Subject($value))->getFieldValue());
    }

    #[Test]
    public function reportsFieldName(): void
    {
        static::assertSame('Subject', (new Subject('test'))->getFieldName());
    }

    #[DataProvider('subjectValueProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rendersSubjectHeaderLine(string $decoded, string $encoded): void
    {
        static::assertSame("Subject: {$encoded}", (new Subject($decoded))->toString());
    }

    #[DataProvider('subjectValueProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function keepsDecodedValue(string $decoded): void
    {
        static::assertSame($decoded, (new Subject($decoded))->getFieldValue());
    }

    #[DataProvider('subjectValueProvider')]
    #[Test]
    public function decodesEncodedSubjectFromString(string $decoded, string $encoded): void
    {
        static::assertSame($decoded, Subject::fromString("Subject:{$encoded}")->getFieldValue());
    }

    #[DataProvider('subjectValueProvider')]
    #[Test]
    public function survivesRoundTripThroughHeaderLine(string $decoded): void
    {
        static::assertSame($decoded, Subject::fromString((new Subject($decoded))->toString())->getFieldValue());
    }

    #[Test]
    public function survivesRoundTripOfLongUtf8Subject(): void
    {
        $value = rtrim(str_repeat('Accents òàùèéì ', times: 10));

        static::assertSame($value, Subject::fromString((new Subject($value))->toString())->getFieldValue());
    }

    #[Test]
    public function parsesHeaderNameCaseInsensitively(): void
    {
        static::assertSame('test', Subject::fromString('SUBJECT: test')->getFieldValue());
    }

    #[Test]
    public function unfoldsFoldedSubjectFromString(): void
    {
        static::assertSame('foo bar', Subject::fromString("Subject: foo\r\n bar")->getFieldValue());
    }

    #[DataProvider('injectedHeaderLineProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsHeaderLineWithLineBreaksInValue(string $value): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header value detected');

        Subject::fromString("Subject:{$value}");
    }

    #[Test]
    public function rejectsHeaderLineOfAnotherHeader(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for Subject string');

        Subject::fromString('Foo: bar');
    }

    #[Test]
    public function rejectsSubjectThatIsNotUtf8(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Subject value must be composed of printable US-ASCII or UTF-8 characters.');

        new Subject("invalid \xff\xfe");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function subjectValueProvider(): array
    {
        return [
            'empty'       => ['', ''],
            'ASCII'       => ['azAZ09-_', 'azAZ09-_'],
            'UTF-8'       => ['ázÁZ09-_', '=?UTF-8?Q?=C3=A1z=C3=81Z09-=5F?='],
            'accents'     => [
                'Accents òàùèéì',
                '=?UTF-8?Q?Accents=20=C3=B2=C3=A0=C3=B9=C3=A8=C3=A9=C3=AC?=',
            ],
            'newline'     => ["xxx yyy\n", '=?UTF-8?Q?xxx=20yyy=0A?='],
            'cr-lf'       => ["xxx yyy\r\n", '=?UTF-8?Q?xxx=20yyy=0D=0A?='],
            'cr-lf twice' => ["xxx yyy\r\n\r\n", '=?UTF-8?Q?xxx=20yyy=0D=0A=0D=0A?='],
            'multiline'   => ["xxx\r\ny\r\nyy", '=?UTF-8?Q?xxx=0D=0Ay=0D=0Ayy?='],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedHeaderLineProvider(): array
    {
        return [
            'newline'     => ["xxx yyy\n"],
            'cr-lf'       => ["xxx yyy\r\n"],
            'cr-lf twice' => ["xxx yyy\r\n\r\n"],
            'multiline'   => ["xxx\r\ny\r\nyy"],
        ];
    }

    /**
     * A leading space before a word too long for the first line folds the value onto the next line.
     */
    #[Test]
    public function startsValueAfterLeadingSpaceOnNextLine(): void
    {
        $word = str_repeat('a', times: 80);

        static::assertSame("Subject:\r\n {$word}", (new Subject(" {$word}"))->toString());
    }
}
