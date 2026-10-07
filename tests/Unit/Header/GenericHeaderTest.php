<?php

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\Exception;
use Contenir\Mail\Header\Exception\InvalidArgumentException;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Header\HeaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;

#[CoversClass(\Contenir\Mail\Header\GenericHeader::class)]
class GenericHeaderTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidHeaderLines(): array
    {
        return [
            'append-chr-32'            => [
                'Content-Type' . chr(32) . ': text/html',
                'Invalid header name detected',
            ],
            'newline-non-continuation' => [
                'Content-Type: text/html; charset = "iso-8859-1"' . "\nThis is a test",
                'Invalid header value detected',
            ],
            'missing-colon'            => [
                'content-type text/html',
                'Header must match with the format "name:value"',
            ],
        ];
    }

    #[Test]
    #[DataProvider('invalidHeaderLines')]
    #[Group('ZF2015-04')]
    public function splitHeaderLineRaisesExceptionOnInvalidHeader(string $line, string $message): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        GenericHeader::splitHeaderLine($line);
    }

    /** @return array<string, array{0: string|null}> */
    public static function fieldNames(): array
    {
        return [
            'append-chr-13'  => ['Subject' . chr(13)],
            'append-chr-127' => ['Subject' . chr(127)],
            'non-string'     => [null],
        ];
    }

    #[Test]
    #[DataProvider('fieldNames')]
    #[Group('ZF2015-04')]
    public function constructorRaisesExceptionOnInvalidFieldName(?string $fieldName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name');
        /** @psalm-suppress MixedArgument */
        new GenericHeader($fieldName);
    }

    /**
     */
    #[Test]
    #[DataProvider('fieldNames')]
    #[Group('ZF2015-04')]
    public function setFieldNameRaisesExceptionOnInvalidFieldName(?string $fieldName): void
    {
        $header = new GenericHeader('Subject');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name');
        /** @psalm-suppress MixedArgument */
        $header->setFieldName($fieldName);
    }

    /** @return array<string, array{0: string}> */
    public static function fieldValues(): array
    {
        return [
            'empty-lines'             => ["\n\n\r\n\r\n\n"],
            'trailing-newlines'       => ["Value\n\n\r\n\r\n\n"],
            'leading-newlines'        => ["\n\n\r\n\r\n\nValue"],
            'surrounding-newlines'    => ["\n\n\r\n\r\n\nValue\n\n\r\n\r\n\n"],
            'split-value'             => ["Some\n\n\r\n\r\n\nValue"],
            'leading-split-value'     => ["\n\n\r\n\r\n\nSome\n\n\r\n\r\n\nValue"],
            'trailing-split-value'    => ["Some\n\n\r\n\r\n\nValue\n\n\r\n\r\n\n"],
            'surrounding-split-value' => ["\n\n\r\n\r\n\nSome\n\n\r\n\r\n\nValue\n\n\r\n\r\n\n"],
        ];
    }

    #[Test]
    #[DataProvider('fieldValues')]
    #[Group('ZF2015-04')]
    public function cRLFsequencesAreEncodedOnToString(string $fieldValue): void
    {
        $header = new GenericHeader('Foo');
        $header->setFieldValue($fieldValue);

        $serialized = $header->toString();
        static::assertStringNotContainsString("\n", $serialized);
        static::assertStringNotContainsString("\r", $serialized);
    }

    #[Test]
    #[DataProvider('validFieldValuesProvider')]
    #[Group('ZF2015-04')]
    public function parseValidSubjectHeader(string $decodedValue, string $encodedValue, string $encoding): void
    {
        $header = GenericHeader::fromString('Foo:' . $encodedValue);

        static::assertSame($decodedValue, $header->getFieldValue());
        static::assertSame($encoding, $header->getEncoding());
    }

    #[Test]
    #[DataProvider('validFieldValuesProvider')]
    #[Group('ZF2015-04')]
    public function setFieldValueValidValue(string $decodedValue, string $encodedValue, string $encoding): void
    {
        $header = new GenericHeader('Foo');
        $header->setFieldValue($decodedValue);

        static::assertSame($decodedValue, $header->getFieldValue());
        static::assertSame('Foo: ' . $encodedValue, $header->toString());
        static::assertSame($encoding, $header->getEncoding());
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function validFieldValuesProvider(): array
    {
        return [
            // Description => [decoded format, encoded format, encoding],
            //'Empty' => array('', '', 'ASCII'),

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

    #[Test]
    #[Group('ZF2015-04')]
    public function castingToStringHandlesContinuationsProperly(): void
    {
        $encoded = '=?UTF-8?Q?foo=0D=0A=20bar?=';
        $raw     = "foo\r\n bar";

        $header = new GenericHeader('Foo');
        $header->setFieldValue($raw);

        static::assertSame($raw, $header->getFieldValue());
        static::assertSame($encoded, $header->getFieldValue(HeaderInterface::FORMAT_ENCODED));
        static::assertSame('Foo: ' . $encoded, $header->toString());
    }

    #[Test]
    public function allowZeroInHeaderValueInConstructor(): void
    {
        /** @psalm-suppress InvalidArgument $header */
        $header = new GenericHeader('Foo', 0);
        static::assertEquals(0, $header->getFieldValue());
        static::assertSame('Foo: 0', $header->toString());
    }

    #[Test]
    public function defaultEncoding(): void
    {
        $header = new GenericHeader('Foo');
        static::assertSame('ASCII', $header->getEncoding());
    }

    #[Test]
    public function setEncoding(): void
    {
        $header = new GenericHeader('Foo');
        $header->setEncoding('UTF-8');
        static::assertSame('UTF-8', $header->getEncoding());
    }

    #[Test]
    public function cannotInstantiateWithoutFieldName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GenericHeader();
    }

    #[Test]
    public function changeEncodingToAsciiNotAllowedWhenHeaderValueContainsUtf8Characters(): void
    {
        $subject = new GenericHeader('Subject');
        $subject->setFieldValue('Accents òàùèéì');

        static::assertSame('UTF-8', $subject->getEncoding());

        $subject->setEncoding('ASCII');
        static::assertSame('UTF-8', $subject->getEncoding());
    }

    #[Test]
    public function changeEncodingBackToAscii(): void
    {
        $subject = new GenericHeader('X-Test');
        $subject->setFieldValue('test');

        static::assertSame('ASCII', $subject->getEncoding());

        $subject->setEncoding('UTF-8');
        static::assertSame('UTF-8', $subject->getEncoding());

        $subject->setEncoding('ASCII');
        static::assertSame('ASCII', $subject->getEncoding());
    }

    #[Test]
    public function setNullEncoding(): void
    {
        $subject = GenericHeader::fromString('X-Test: test');
        static::assertSame('ASCII', $subject->getEncoding());

        $subject->setEncoding(null);
        static::assertSame('ASCII', $subject->getEncoding());
    }

    #[Test]
    public function settingFieldValueCanChangeEncoding(): void
    {
        $subject = GenericHeader::fromString('X-Test: test');
        static::assertSame('ASCII', $subject->getEncoding());

        $subject->setFieldValue('Accents òàùèéì');
        static::assertSame('UTF-8', $subject->getEncoding());
    }

    #[Test]
    public function settingTheSameEncoding(): void
    {
        $subject = GenericHeader::fromString('X-Test: test');
        static::assertSame('ASCII', $subject->getEncoding());

        $subject->setEncoding('ASCII');
        static::assertSame('ASCII', $subject->getEncoding());
    }
}
