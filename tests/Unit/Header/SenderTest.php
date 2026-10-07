<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Address;
use Contenir\Mail\Exception;
use Contenir\Mail\Header;
use Contenir\Mail\Header\HeaderInterface;
use Contenir\Mail\Header\Sender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_merge;
use function array_slice;

#[CoversClass(Sender::class)]
class SenderTest extends TestCase
{
    #[Test]
    public function fromStringCreatesValidReceivedHeader(): void
    {
        $sender = Header\Sender::fromString('Sender: <foo@bar>');
        static::assertInstanceOf(HeaderInterface::class, $sender);
        static::assertInstanceOf(Sender::class, $sender);
    }

    #[Test]
    public function getFieldNameReturnsHeaderName(): void
    {
        $sender = new Header\Sender();
        static::assertSame('Sender', $sender->getFieldName());
    }

    #[Test]
    #[DataProvider('validSenderHeaderDataProvider')]
    #[Group('ZF2015-04')]
    public function parseValidSenderHeader(string $expectedFieldValue, string $encodedValue, string $encoding): void
    {
        $header = Header\Sender::fromString("Sender:{$encodedValue}");

        static::assertSame($expectedFieldValue, $header->getFieldValue());
        static::assertSame($encoding, $header->getEncoding());
    }

    /**
     * @param string $decodedValue
     * @param string $expectedException
     */
    #[Test]
    #[DataProvider('invalidSenderEncodedDataProvider')]
    #[Group('ZF2015-04')]
    public function parseInvalidSenderHeaderThrowException(
        $decodedValue,
        $expectedException,
    ): void {
        $this->expectException($expectedException);
        Header\Sender::fromString("Sender:{$decodedValue}");
    }

    /**
     * @param string $email
     * @param null|string $name
     * @param string $encodedValue
     * @param string $expectedFieldValue,
     * @param string $encoding
     */
    #[Test]
    #[DataProvider('validSenderDataProvider')]
    #[Group('ZF2015-04')]
    public function setAddressValidValue($email, $name, $expectedFieldValue, $encodedValue, $encoding): void
    {
        $header = new Header\Sender();
        $header->setAddress($email, $name);

        static::assertSame($expectedFieldValue, $header->getFieldValue());
        static::assertSame("Sender: {$encodedValue}", $header->toString());
        static::assertSame($encoding, $header->getEncoding());
    }

    /**
     * @param string $email
     * @param null|string $name
     */
    #[Test]
    #[DataProvider('invalidSenderDataProvider')]
    #[Group('ZF2015-04')]
    public function setAddressInvalidValue($email, $name): void
    {
        $header = new Header\Sender();
        $this->expectException(Exception\InvalidArgumentException::class);
        $header->setAddress($email, $name);
    }

    /**
     * @param string $email
     * @param null|string $name
     * @param string $expectedFieldValue,
     * @param string $encodedValue
     * @param string $encoding
     */
    #[Test]
    #[DataProvider('validSenderDataProvider')]
    #[Group('ZF2015-04')]
    public function setAddressValidAddressObject($email, $name, $expectedFieldValue, $encodedValue, $encoding): void
    {
        $address = new Address($email, $name);

        $header = new Header\Sender();
        $header->setAddress($address);

        static::assertSame($address, $header->getAddress());
        static::assertSame($expectedFieldValue, $header->getFieldValue());
        static::assertSame("Sender: {$encodedValue}", $header->toString());
        static::assertSame($encoding, $header->getEncoding());
    }

    public static function validSenderDataProvider(): array
    {
        return [
            // Description => [sender address, sender name, getFieldValue, encoded version, encoding],
            'ASCII address' => [
                'foo@bar',
                null,
                '<foo@bar>',
                '<foo@bar>',
                'ASCII',
            ],
            'ASCII name'    => [
                'foo@bar',
                'foo',
                'foo <foo@bar>',
                'foo <foo@bar>',
                'ASCII',
            ],
            'UTF-8 name'    => [
                'foo@bar',
                'ázÁZ09',
                'ázÁZ09 <foo@bar>',
                '=?UTF-8?Q?=C3=A1z=C3=81Z09?= <foo@bar>',
                'UTF-8',
            ],
        ];
    }

    public static function validSenderHeaderDataProvider(): array
    {
        return array_merge(
            array_map(static fn($parameters) => array_slice($parameters, 2), self::validSenderDataProvider()),
            [
                // Per RFC 2822, 3.4 and 3.6.2, "Sender: foo@bar" is valid.
                'Unbracketed email' => [
                    '<foo@bar>',
                    'foo@bar',
                    'ASCII',
                ],
            ],
        );
    }

    public static function invalidSenderDataProvider(): array
    {
        $mailInvalidArgumentException = Exception\InvalidArgumentException::class;

        return [
            // Description => [sender address, sender name, exception class, exception message],
            'Empty'      => ['', null, $mailInvalidArgumentException, null],
            'any ASCII'  => ['azAZ09-_', null, $mailInvalidArgumentException, null],
            'any UTF-8'  => ['ázÁZ09-_', null, $mailInvalidArgumentException, null],
            'non-string' => [null, null, $mailInvalidArgumentException, null],

            // CRLF @group ZF2015-04 cases
            ["foo@bar\n", null, $mailInvalidArgumentException, null],
            ["foo@bar\r", null, $mailInvalidArgumentException, null],
            ["foo@bar\r\n", null, $mailInvalidArgumentException, null],
            ['foo@bar', "\r", $mailInvalidArgumentException, null],
            ['foo@bar', "\n", $mailInvalidArgumentException, null],
            ['foo@bar', "\r\n", $mailInvalidArgumentException, null],
            ['foo@bar', "foo\r\nevilBody", $mailInvalidArgumentException, null],
            ['foo@bar', "\r\nevilBody", $mailInvalidArgumentException, null],
        ];
    }

    public static function invalidSenderEncodedDataProvider(): array
    {
        $mailInvalidArgumentException   = Exception\InvalidArgumentException::class;
        $headerInvalidArgumentException = Header\Exception\InvalidArgumentException::class;

        return [
            // Description => [decoded format, exception class, exception message],
            'Empty'     => ['', $mailInvalidArgumentException],
            'any ASCII' => ['azAZ09-_', $mailInvalidArgumentException],
            'any UTF-8' => ['ázÁZ09-_', $mailInvalidArgumentException],
            ["xxx yyy\n", $mailInvalidArgumentException],
            ["xxx yyy\r\n", $mailInvalidArgumentException],
            ["xxx yyy\r\n\r\n", $mailInvalidArgumentException],
            ["xxx\r\ny\r\nyy", $mailInvalidArgumentException],
            ["foo\r\n@\r\nbar", $mailInvalidArgumentException],
            ['ázÁZ09 <foo@bar>', $headerInvalidArgumentException],
            'newline'   => ["<foo@bar>\n", $headerInvalidArgumentException],
            'cr-lf'     => ["<foo@bar>\r\n", $headerInvalidArgumentException],
            'cr-lf-wsp' => ["<foo@bar>\r\n\r\n", $headerInvalidArgumentException],
            'multiline' => ["<foo\r\n@\r\nbar>", $headerInvalidArgumentException],
        ];
    }

    /**
     * @param string $headerString
     * @param string $expectedName
     * @param string $expectedEmail
     */
    #[Test]
    #[DataProvider('validHeaderLinesProvider')]
    public function fromStringWithValidInput($headerString, $expectedName, $expectedEmail): void
    {
        $header = Header\Sender::fromString($headerString);

        static::assertSame($expectedName, $header->getAddress()->getName());
        static::assertSame($expectedEmail, $header->getAddress()->getEmail());
    }

    public static function validHeaderLinesProvider(): array
    {
        // @codingStandardsIgnoreStart
        return [
            // [ header line,                                  expected sender name, expected email address ]
            ['Sender: foo@bar',                                null,           'foo@bar'],
            ['Sender: <foo@bar>',                              null,           'foo@bar'],
            ['Sender:    foo@bar',                             null,           'foo@bar'],
            ['Sender: name <foo@bar>',                         'name',         'foo@bar'],
            ['Sender: <weird name> <foo@bar>',                 '<weird name>', 'foo@bar'],
            ['Sender: moar words <foo@bar>',                   'moar words',   'foo@bar'],
            ['Sender: =?UTF-8?Q?=C3=A1z=C3=81Z09?= <foo@bar>', 'ázÁZ09',       'foo@bar'],
        ];

        // @codingStandardsIgnoreEnd
    }

    /**
     * @param string $headerString
     * @param string $expectedException
     * @param string $expectedMessagePart
     */
    #[Test]
    #[DataProvider('invalidHeaderLinesProvider')]
    public function fromStringWithInvalidInput($headerString, $expectedException, $expectedMessagePart = ''): void
    {
        $this->expectException($expectedException);
        if ($expectedMessagePart) {
            $this->expectExceptionMessage($expectedMessagePart);
        }

        Header\Sender::fromString($headerString);
    }

    public static function invalidHeaderLinesProvider(): array
    {
        $mailInvalidArgumentException   = Exception\InvalidArgumentException::class;
        $headerInvalidArgumentException = Header\Exception\InvalidArgumentException::class;

        return [
            ['Sender: foo',       $mailInvalidArgumentException],
            ['Sender: foo<foo>',  $mailInvalidArgumentException],
            ['Sender: foo foo',   $headerInvalidArgumentException],
            ['Sender: <foo> foo', $headerInvalidArgumentException],
        ];
    }

    #[Test]
    public function defaultEncoding(): void
    {
        $header = new Header\Sender();
        static::assertSame('ASCII', $header->getEncoding());
    }

    #[Test]
    public function setEncoding(): void
    {
        $header = new Header\Sender();
        $header->setEncoding('UTF-8');
        static::assertSame('UTF-8', $header->getEncoding());
    }

    #[Test]
    public function fromStringRaisesExceptionOnInvalidHeader(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header name for Sender string');
        Header\Sender::fromString('Foo: bar');
    }
}
