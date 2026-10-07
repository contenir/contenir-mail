<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Address;
use Contenir\Mail\Exception;
use Contenir\Mail\Header;
use Contenir\Mail\Header\AddressEncoder;
use Contenir\Mail\Header\AddressListCodec;
use Contenir\Mail\Header\Sender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sender::class)]
#[CoversClass(AddressListCodec::class)]
#[CoversClass(AddressEncoder::class)]
#[Group('unit')]
final class SenderTest extends TestCase
{
    #[Test]
    public function reportsFieldName(): void
    {
        static::assertSame('Sender', (new Sender(new Address('foo@bar')))->getFieldName());
    }

    #[Test]
    public function exposesTheAddressItWasGiven(): void
    {
        $address = new Address('foo@bar', 'foo');

        static::assertSame($address, (new Sender($address))->getAddress());
    }

    #[DataProvider('senderProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rendersDecodedFieldValue(string $email, ?string $name, string $expected): void
    {
        static::assertSame($expected, (new Sender(new Address($email, $name)))->getFieldValue());
    }

    #[DataProvider('senderProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rendersEncodedHeaderLine(string $email, ?string $name, string $decoded, string $encoded): void
    {
        static::assertSame("Sender: {$encoded}", (new Sender(new Address($email, $name)))->toString());
    }

    #[DataProvider('senderProvider')]
    #[Test]
    public function parsesEncodedHeaderLine(string $email, ?string $name, string $decoded, string $encoded): void
    {
        static::assertSame($decoded, Sender::fromString("Sender:{$encoded}")->getFieldValue());
    }

    #[Test]
    public function convertsNonAsciiDomainToPunycode(): void
    {
        static::assertSame(
            'Sender: user@xn--bcher-kva.example',
            (new Sender(new Address('user@bücher.example')))->toString(),
        );
    }

    #[Test]
    public function keepsNonAsciiDomainInDecodedFieldValue(): void
    {
        static::assertSame(
            'user@bücher.example',
            (new Sender(new Address('user@bücher.example')))->getFieldValue(),
        );
    }

    /**
     * @param array{?string, string} $expected
     */
    #[DataProvider('validHeaderLineProvider')]
    #[Test]
    public function parsesNameAndEmailFromHeaderLine(string $headerLine, array $expected): void
    {
        $address = Sender::fromString($headerLine)->getAddress();

        static::assertSame($expected, [$address->getName(), $address->getEmail()]);
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('invalidHeaderLineProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsInvalidHeaderLine(string $headerLine, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        Sender::fromString($headerLine);
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('invalidAddressProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsInvalidAddress(string $email, ?string $name, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        new Sender(new Address($email, $name));
    }

    #[DataProvider('trailingLineBreakProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsTrailingLineBreakInEmail(string $email): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('CRLF injection detected');

        new Sender(new Address($email));
    }

    #[DataProvider('hostileNameProvider')]
    #[Test]
    public function displayNameCannotChangeTheAddressWhenReparsed(string $name): void
    {
        $reparsed = Sender::fromString((new Sender(new Address('victim@example.com', $name)))->toString());

        static::assertSame('victim@example.com', $reparsed->getAddress()->getEmail());
    }

    /**
     * @return array<string, array{string, ?string, string, string}>
     */
    public static function senderProvider(): array
    {
        return [
            'ASCII address'           => ['foo@bar', null, 'foo@bar', 'foo@bar'],
            'ASCII name'              => ['foo@bar', 'foo', 'foo <foo@bar>', 'foo <foo@bar>'],
            'UTF-8 name'              => [
                'foo@bar',
                'ázÁZ09',
                'ázÁZ09 <foo@bar>',
                '=?UTF-8?Q?=C3=A1z=C3=81Z09?= <foo@bar>',
            ],
            'name with comma'         => [
                'foo@bar',
                'Last, First',
                '"Last, First" <foo@bar>',
                '"Last, First" <foo@bar>',
            ],
            'name with angle bracket' => [
                'foo@bar',
                '<weird name>',
                '"<weird name>" <foo@bar>',
                '"<weird name>" <foo@bar>',
            ],
        ];
    }

    /**
     * @return array<string, array{string, array{?string, string}}>
     */
    public static function validHeaderLineProvider(): array
    {
        return [
            'unbracketed email'        => ['Sender: foo@bar', [null, 'foo@bar']],
            'bracketed email'          => ['Sender: <foo@bar>', [null, 'foo@bar']],
            'extra leading whitespace' => ['Sender:    foo@bar', [null, 'foo@bar']],
            'lower-case header name'   => ['sender: foo@bar', [null, 'foo@bar']],
            'name'                     => ['Sender: name <foo@bar>', ['name', 'foo@bar']],
            'name in angle brackets'   => ['Sender: <weird name> <foo@bar>', ['<weird name>', 'foo@bar']],
            'several words'            => ['Sender: moar words <foo@bar>', ['moar words', 'foo@bar']],
            'quoted name'              => ['Sender: "Last, First" <foo@bar>', ['Last, First', 'foo@bar']],
            'RFC 2047 encoded name'    => [
                'Sender: =?UTF-8?Q?=C3=A1z=C3=81Z09?= <foo@bar>',
                ['ázÁZ09', 'foo@bar'],
            ],
        ];
    }

    /**
     * @return array<string, array{string, class-string<\Throwable>, string}>
     */
    public static function invalidHeaderLineProvider(): array
    {
        $headerException = Header\Exception\InvalidArgumentException::class;
        $mailException   = Exception\InvalidArgumentException::class;
        $invalidEmail    = 'The input is not a valid email address. Use the basic format local-part@hostname';

        return [
            'another header'          => ['Foo: bar', $headerException, 'Invalid header name for Sender string'],
            'empty'                   => ['Sender: ', $headerException, 'Invalid header value for Sender string'],
            'ASCII without at-sign'   => ['Sender: azAZ09-_', $mailException, $invalidEmail],
            'single word'             => ['Sender: foo', $mailException, $invalidEmail],
            'word before bracket'     => ['Sender: foo<foo>', $mailException, $invalidEmail],
            'two words'               => [
                'Sender: foo foo',
                $headerException,
                'Invalid header value for Sender string',
            ],
            'word after bracket'      => [
                'Sender: <foo> foo',
                $headerException,
                'Invalid header value for Sender string',
            ],
            'raw UTF-8'               => ['Sender: ázÁZ09-_', $headerException, 'Invalid header value detected'],
            'raw UTF-8 name'          => [
                'Sender: ázÁZ09 <foo@bar>',
                $headerException,
                'Invalid header value detected',
            ],
            'newline'                 => ["Sender: xxx yyy\n", $headerException, 'Invalid header value detected'],
            'cr-lf'                   => ["Sender: xxx yyy\r\n", $headerException, 'Invalid header value detected'],
            'cr-lf twice'             => ["Sender: xxx yyy\r\n\r\n", $headerException, 'Invalid header value detected'],
            'multiline'               => ["Sender: xxx\r\ny\r\nyy", $headerException, 'Invalid header value detected'],
            'line breaks around at'   => ["Sender: foo\r\n@\r\nbar", $headerException, 'Invalid header value detected'],
            'address then newline'    => ["Sender: <foo@bar>\n", $headerException, 'Invalid header value detected'],
            'address then cr-lf'      => ["Sender: <foo@bar>\r\n", $headerException, 'Invalid header value detected'],
            'address then two cr-lf'  => [
                "Sender: <foo@bar>\r\n\r\n",
                $headerException,
                'Invalid header value detected',
            ],
            'line breaks in brackets' => [
                "Sender: <foo\r\n@\r\nbar>",
                $headerException,
                'Invalid header value detected',
            ],
        ];
    }

    /**
     * @return array<string, array{string, ?string, class-string<\Throwable>, string}>
     */
    public static function invalidAddressProvider(): array
    {
        $exception    = Exception\InvalidArgumentException::class;
        $invalidEmail = 'The input is not a valid email address. Use the basic format local-part@hostname';
        $crlf         = 'CRLF injection detected';

        return [
            'empty'                 => ['', null, $exception, 'Email must be a valid email address'],
            'ASCII without at-sign' => ['azAZ09-_', null, $exception, $invalidEmail],
            'UTF-8 without at-sign' => ['ázÁZ09-_', null, $exception, $invalidEmail],
            'cr in name'            => ['foo@bar', "\r", $exception, $crlf],
            'lf in name'            => ['foo@bar', "\n", $exception, $crlf],
            'cr-lf in name'         => ['foo@bar', "\r\n", $exception, $crlf],
            'body after name'       => ['foo@bar', "foo\r\nevilBody", $exception, $crlf],
            'body injected as name' => ['foo@bar', "\r\nevilBody", $exception, $crlf],
            'cr-lf inside email'    => ["foo\r\n@bar", null, $exception, $crlf],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function trailingLineBreakProvider(): array
    {
        return [
            'newline' => ["foo@bar\n"],
            'cr'      => ["foo@bar\r"],
            'cr-lf'   => ["foo@bar\r\n"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileNameProvider(): array
    {
        return [
            'quote breaks out'        => ['a" <attacker@example.net>'],
            'escaped backslash quote' => ['a\\" <attacker@example.net>'],
            'angle-bracketed address' => ['<attacker@example.net>'],
            'UTF-8 with address'      => ['é <attacker@example.net>'],
        ];
    }
}
