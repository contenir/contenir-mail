<?php

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header;
use Contenir\Mail\Header\Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(\Contenir\Mail\Header\Subject::class)]
class SubjectTest extends TestCase
{
    #[Test]
    public function headerFolding(): void
    {
        $string  = str_repeat('foobarblahblahblah baz bat', 10);
        $subject = new Header\Subject();
        $subject->setSubject($string);

        $expected =
            "foobarblahblahblah baz batfoobarblahblahblah baz\r\n "
            . "batfoobarblahblahblah baz batfoobarblahblahblah baz batfoobarblahblahblah baz\r\n "
            . "batfoobarblahblahblah baz batfoobarblahblahblah baz batfoobarblahblahblah baz\r\n "
            . 'batfoobarblahblahblah baz batfoobarblahblahblah baz bat';
        $test = $subject->getFieldValue(Header\HeaderInterface::FORMAT_ENCODED);
        static::assertSame($expected, $test);
    }

    #[Test]
    public function defaultEncoding(): void
    {
        $header = Header\Subject::fromString('Subject: test');
        static::assertSame('ASCII', $header->getEncoding());
    }

    #[Test]
    public function setEncoding(): void
    {
        $header = Header\Subject::fromString('Subject: test');
        $header->setEncoding('UTF-8');
        static::assertSame('UTF-8', $header->getEncoding());
    }

    /**
     * @param string $decodedValue
     * @param string $encodedValue
     * @param string $encoding
     */
    #[Test]
    #[DataProvider('validSubjectValuesProvider')]
    #[Group('ZF2015-04')]
    public function parseValidSubjectHeader($decodedValue, $encodedValue, $encoding): void
    {
        $header = Header\Subject::fromString('Subject:' . $encodedValue);

        static::assertSame($decodedValue, $header->getFieldValue());
        static::assertSame($encoding, $header->getEncoding());
    }

    /**
     * @param string $decodedValue
     * @param string $expectedException
     * @param string|null $expectedExceptionMessage
     */
    #[Test]
    #[DataProvider('invalidSubjectValuesProvider')]
    #[Group('ZF2015-04')]
    public function parseInvalidSubjectHeaderThrowException(
        $decodedValue,
        $expectedException,
        $expectedExceptionMessage,
    ): void {
        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedExceptionMessage);
        Header\Subject::fromString('Subject:' . $decodedValue);
    }

    #[Test]
    public function fromStringRaisesExceptionOnInvalidHeader(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for Subject string');
        Header\Subject::fromString('Foo: bar');
    }

    /**
     * @param string $decodedValue
     * @param string $encodedValue
     * @param string $encoding
     */
    #[Test]
    #[DataProvider('validSubjectValuesProvider')]
    #[Group('ZF2015-04')]
    public function setSubjectValidValue($decodedValue, $encodedValue, $encoding): void
    {
        $header = new Header\Subject();
        $header->setSubject($decodedValue);

        static::assertSame($decodedValue, $header->getFieldValue());
        static::assertSame('Subject: ' . $encodedValue, $header->toString());
        static::assertSame($encoding, $header->getEncoding());
    }

    public static function validSubjectValuesProvider(): array
    {
        return [
            // Description => [decoded format, encoded format, encoding],
            'Empty' => ['', '', 'ASCII'],

            // Encoding cases
            'ASCII charset' => ['azAZ09-_', 'azAZ09-_', 'ASCII'],
            'UTF-8 charset' => ['ázÁZ09-_', '=?UTF-8?Q?=C3=A1z=C3=81Z09-=5F?=', 'UTF-8'],

            // CRLF @group ZF2015-04 cases
            'newline'   => ["xxx yyy\n", '=?UTF-8?Q?xxx=20yyy=0A?=', 'UTF-8'],
            'cr-lf'     => ["xxx yyy\r\n", '=?UTF-8?Q?xxx=20yyy=0D=0A?=', 'UTF-8'],
            'cr-lf-wsp' => ["xxx yyy\r\n\r\n", '=?UTF-8?Q?xxx=20yyy=0D=0A=0D=0A?=', 'UTF-8'],
            'multiline' => ["xxx\r\ny\r\nyy", '=?UTF-8?Q?xxx=0D=0Ay=0D=0Ayy?=', 'UTF-8'],
        ];
    }

    public static function invalidSubjectValuesProvider(): array
    {
        $invalidArgumentException   = Exception\InvalidArgumentException::class;
        $invalidHeaderValueDetected = 'Invalid header value detected';

        return [
            // Description => [decoded format, exception class, exception message],
            'newline'   => ["xxx yyy\n", $invalidArgumentException, $invalidHeaderValueDetected],
            'cr-lf'     => ["xxx yyy\r\n", $invalidArgumentException, $invalidHeaderValueDetected],
            'cr-lf-wsp' => ["xxx yyy\r\n\r\n", $invalidArgumentException, $invalidHeaderValueDetected],
            'multiline' => ["xxx\r\ny\r\nyy", $invalidArgumentException, $invalidHeaderValueDetected],
        ];
    }

    #[Test]
    public function changeEncodingToAsciiNotAllowedWhenSubjectContainsUtf8Characters(): void
    {
        $subject = new Header\Subject();
        $subject->setSubject('Accents òàùèéì');

        static::assertSame('UTF-8', $subject->getEncoding());

        $subject->setEncoding('ASCII');
        static::assertSame('UTF-8', $subject->getEncoding());
    }

    #[Test]
    public function changeEncodingBackToAscii(): void
    {
        $subject = new Header\Subject();
        $subject->setSubject('test');

        static::assertSame('ASCII', $subject->getEncoding());

        $subject->setEncoding('UTF-8');
        static::assertSame('UTF-8', $subject->getEncoding());

        $subject->setEncoding('ASCII');
        static::assertSame('ASCII', $subject->getEncoding());
    }

    #[Test]
    public function setNullEncoding(): void
    {
        $subject = Header\Subject::fromString('Subject: test');
        static::assertSame('ASCII', $subject->getEncoding());

        $subject->setEncoding(null);
        static::assertSame('ASCII', $subject->getEncoding());
    }

    #[Test]
    public function settingSubjectCanChangeEncoding(): void
    {
        $subject = Header\Subject::fromString('Subject: test');
        static::assertSame('ASCII', $subject->getEncoding());

        $subject->setSubject('Accents òàùèéì');
        static::assertSame('UTF-8', $subject->getEncoding());
    }

    #[Test]
    public function settingTheSameEncoding(): void
    {
        $subject = Header\Subject::fromString('Subject: test');
        static::assertSame('ASCII', $subject->getEncoding());

        $subject->setEncoding('ASCII');
        static::assertSame('ASCII', $subject->getEncoding());
    }
}
