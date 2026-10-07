<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Address;
use Contenir\Mail\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Address::class)]
#[Group('unit')]
final class AddressTest extends TestCase
{
    #[Test]
    public function keepsEmail(): void
    {
        static::assertSame('test@example.com', (new Address('test@example.com'))->getEmail());
    }

    #[Test]
    public function hasNoNameWhenNoneIsGiven(): void
    {
        static::assertNull((new Address('test@example.com'))->getName());
    }

    #[Test]
    public function keepsName(): void
    {
        static::assertSame('Example Test', (new Address('test@example.com', 'Example Test'))->getName());
    }

    #[Test]
    public function keepsComment(): void
    {
        static::assertSame('work', (new Address('test@example.com', comment: 'work'))->getComment());
    }

    #[Test]
    public function trimsEmail(): void
    {
        static::assertSame('test@example.com', (new Address('  test@example.com  '))->getEmail());
    }

    #[Test]
    public function trimsName(): void
    {
        static::assertSame('Example Test', (new Address('test@example.com', '  Example Test  '))->getName());
    }

    #[Test]
    public function trimsComment(): void
    {
        static::assertSame('work', (new Address('test@example.com', comment: '  work  '))->getComment());
    }

    #[DataProvider('blankProvider')]
    #[Test]
    public function treatsBlankNameAsNoName(string $name): void
    {
        static::assertNull((new Address('test@example.com', $name))->getName());
    }

    #[DataProvider('blankProvider')]
    #[Test]
    public function treatsBlankCommentAsNoComment(string $comment): void
    {
        static::assertNull((new Address('test@example.com', comment: $comment))->getComment());
    }

    #[Test]
    public function acceptsInternationalisedDomain(): void
    {
        static::assertSame('oau@ä-umlaut.de', (new Address('oau@ä-umlaut.de'))->getEmail());
    }

    #[DataProvider('invalidEmailProvider')]
    #[Test]
    public function rejectsInvalidEmail(string $email, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Address($email);
    }

    /**
     * @param array{string, ?string, ?string} $parts
     */
    #[DataProvider('crlfProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsCrlfInAnyPart(array $parts): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CRLF injection detected');

        new Address(...$parts);
    }

    #[DataProvider('toStringProvider')]
    #[Test]
    public function rendersAddressForHeader(Address $address, string $expected): void
    {
        static::assertSame($expected, $address->toString());
    }

    #[DataProvider('quoteDisplayNameProvider')]
    #[Test]
    public function quotesDisplayNameOnlyWhenItHoldsSpecials(string $name, string $expected): void
    {
        static::assertSame($expected, Address::quoteDisplayName($name));
    }

    /**
     * @param array{?string, ?string} $expected email and name
     */
    #[DataProvider('fromStringProvider')]
    #[Test]
    public function parsesAddressString(string $address, array $expected): void
    {
        $parsed = Address::fromString($address);

        static::assertSame($expected, [$parsed->getEmail(), $parsed->getName()]);
    }

    #[Test]
    public function fromStringKeepsComment(): void
    {
        static::assertSame('work', Address::fromString('test@example.com', 'work')->getComment());
    }

    #[Test]
    public function fromStringRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid address format');

        Address::fromString('');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace'   => ["  \t "],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidEmailProvider(): array
    {
        $format = 'The input is not a valid email address. Use the basic format local-part@hostname';

        return [
            'empty'      => ['', 'Email must be a valid email address'],
            'whitespace' => ['   ', 'Email must be a valid email address'],
            'any ASCII'  => ['azAZ09-_', $format],
            'any UTF-8'  => ['ázÁZ09-_', $format],
        ];
    }

    /**
     * @return array<string, array{array{string, ?string, ?string}}>
     */
    public static function crlfProvider(): array
    {
        return [
            'LF after email'                => [["foo@bar\n", null, null]],
            'CR after email'                => [["foo@bar\r", null, null]],
            'CRLF after email'              => [["foo@bar\r\n", null, null]],
            'CRLF inside email'             => [["foo\r\n@bar", null, null]],
            'name is CR'                    => [['foo@bar', "\r", null]],
            'name is LF'                    => [['foo@bar', "\n", null]],
            'name is CRLF'                  => [['foo@bar', "\r\n", null]],
            'name injects a body'           => [['foo@bar', "foo\r\nevilBody", null]],
            'name starts with CRLF'         => [['foo@bar', "\r\nevilBody", null]],
            'comment injects a header line' => [['foo@bar', null, "work\r\nBcc: attacker@example.net"]],
        ];
    }

    /**
     * @return array<string, array{Address, string}>
     */
    public static function toStringProvider(): array
    {
        return [
            'bare email'               => [new Address('test@example.com'), 'test@example.com'],
            'plain name'               => [
                new Address('test@example.com', 'Example Test'),
                'Example Test <test@example.com>',
            ],
            'name with a full stop'    => [
                new Address('test@example.com', 'John Q. Public'),
                'John Q. Public <test@example.com>',
            ],
            'name with a comma'        => [
                new Address('test@example.com', 'Last, First'),
                '"Last, First" <test@example.com>',
            ],
            'name with a double quote' => [
                new Address('test@example.com', 'The "Boss"'),
                '"The \\"Boss\\"" <test@example.com>',
            ],
            'name with a backslash'    => [
                new Address('test@example.com', 'Back \\ Slash'),
                '"Back \\\\ Slash" <test@example.com>',
            ],
            'comment is not written'   => [new Address('test@example.com', comment: 'work'), 'test@example.com'],
            'non-ASCII name stays raw' => [new Address('test@example.com', 'Jösé'), 'Jösé <test@example.com>'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function quoteDisplayNameProvider(): array
    {
        return [
            'no specials'   => ['Example Test', 'Example Test'],
            'full stop'     => ['John Q. Public', 'John Q. Public'],
            'apostrophe'    => ["Pat O'Brien", "Pat O'Brien"],
            'parentheses'   => ['Name (x)', '"Name (x)"'],
            'angle bracket' => ['Name <x>', '"Name <x>"'],
            'square braket' => ['Name [x]', '"Name [x]"'],
            'colon'         => ['Re: Team', '"Re: Team"'],
            'semicolon'     => ['a; b', '"a; b"'],
            'at sign'       => ['team@work', '"team@work"'],
            'comma'         => ['Last, First', '"Last, First"'],
            'double quote'  => ['The "Boss"', '"The \\"Boss\\""'],
            'backslash'     => ['Back \\ Slash', '"Back \\\\ Slash"'],
        ];
    }

    /**
     * @return array<string, array{string, array{?string, ?string}}>
     */
    public static function fromStringProvider(): array
    {
        return [
            'bare email'                   => ['test@example.com', ['test@example.com', null]],
            'name and email'               => ['Example Test <test@example.com>', ['test@example.com', 'Example Test']],
            'angle brackets only'          => ['<test@example.com>', ['test@example.com', null]],
            'Outlook single quotes'        => ["'test@example.com'", ['test@example.com', null]],
            'Outlook single quotes inside' => [
                "Example Test <'test@example.com'>",
                ['test@example.com', 'Example Test'],
            ],
        ];
    }

    #[Test]
    public function rejectsTextAfterAngleAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a valid hostname for the email address');

        Address::fromString('Name <user@example.com> trailing');
    }

    #[Test]
    public function trimsSpacesAndQuotesInsideAngleBrackets(): void
    {
        static::assertSame('user@example.com', Address::fromString("Name < 'user@example.com' >")->getEmail());
    }

    #[DataProvider('notUtf8Provider')]
    #[Test]
    public function rejectsTextThatIsNotUtf8(string $email, ?string $name, ?string $comment): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Address must be UTF-8 text');

        new Address($email, $name, $comment);
    }

    /**
     * @return array<string, array{string, ?string, ?string}>
     */
    public static function notUtf8Provider(): array
    {
        return [
            'name'    => ['user@example.com', "Caf\xe9", null],
            'comment' => ['user@example.com', null, "Caf\xe9"],
            'email'   => ["caf\xe9@example.com", null, null],
        ];
    }
}
