<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Header\AbstractAddressList;
use Contenir\Mail\Header\AddressEncoder;
use Contenir\Mail\Header\AddressListCodec;
use Contenir\Mail\Header\To;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractAddressList::class)]
#[CoversClass(AddressListCodec::class)]
#[CoversClass(AddressEncoder::class)]
#[CoversClass(Address::class)]
#[Group('unit')]
final class AddressListDisplayNameTest extends TestCase
{
    #[DataProvider('hostileNameProvider')]
    #[Test]
    public function displayNameCannotAddRecipientsWhenReparsed(string $name): void
    {
        $reparsed = To::fromString(self::makeHeader($name)->toString());

        $recipients = [];
        foreach ($reparsed->getAddressList() as $address) {
            $recipients[$address->getEmail()] = $address->getName();
        }

        static::assertSame(['victim@example.com' => $name], $recipients);
    }

    #[DataProvider('quotedNameProvider')]
    #[Test]
    public function quotesAndEscapesDisplayNameContainingSpecials(string $name, string $expected): void
    {
        static::assertSame(
            $expected,
            self::makeHeader($name)->getEncodedFieldValue(),
        );
    }

    #[DataProvider('plainNameProvider')]
    #[Test]
    public function leavesDisplayNameWithoutSpecialsUnquoted(string $name): void
    {
        static::assertSame(
            "{$name} <victim@example.com>",
            self::makeHeader($name)->getEncodedFieldValue(),
        );
    }

    /**
     * RFC 2047, section 5 (3): an encoded-word in a phrase may not hold a raw
     * double quote, so a non-ASCII name with specials must not be quoted and
     * then encoded with the quotes inside the encoded-word.
     */
    #[DataProvider('nonAsciiSpecialsNameProvider')]
    #[Test]
    public function encodedDisplayNameHoldsNoRawDoubleQuote(string $name): void
    {
        static::assertDoesNotMatchRegularExpression(
            '/=\\?UTF-8\\?Q\\?[^?]*"/',
            self::makeHeader($name)->getEncodedFieldValue(),
        );
    }

    /**
     * @param array<string, null|string> $expected
     */
    #[DataProvider('incomingHeaderProvider')]
    #[Test]
    public function parsesQuotedNamesAndGroupsInIncomingHeader(string $headerLine, array $expected): void
    {
        $recipients = [];
        foreach (To::fromString($headerLine)->getAddressList() as $address) {
            $recipients[$address->getEmail()] = $address->getName();
        }

        static::assertSame($expected, $recipients);
    }

    /**
     * @return array<string, array{string, array<string, null|string>}>
     */
    public static function incomingHeaderProvider(): array
    {
        return [
            'quoted name with colon and semicolon'                 => [
                'To: "Re: Team; x" <team@example.com>',
                ['team@example.com' => 'Re: Team; x'],
            ],
            'address before a group'                               => [
                'To: solo@example.com, Group: one@example.com, two@example.com;',
                ['solo@example.com' => null, 'one@example.com' => null, 'two@example.com' => null],
            ],
            'empty group'                                          => [
                'To: Undisclosed recipients:;',
                [],
            ],
            'group member with quoted name containing a semicolon' => [
                'To: Group: "Last; First" <member@example.com>;',
                ['member@example.com' => 'Last; First'],
            ],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileNameProvider(): array
    {
        $names = [
            'quote breaks out before a comma'     => 'a" <attacker@example.net>, "b',
            'quote breaks out before a semicolon' => 'a" <attacker@example.net>; "b',
            'escaped backslash before the quote'  => 'a\\" <attacker@example.net>, "b',
            'group syntax'                        => 'Undisclosed: attacker@example.net;',
        ];

        $cases = [];
        foreach ($names as $label => $name) {
            $cases["{$label} (ASCII)"]     = [$name];
            $cases["{$label} (non-ASCII)"] = ["Jösé {$name}"];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function quotedNameProvider(): array
    {
        return [
            'comma'          => ['Last, First', '"Last, First" <victim@example.com>'],
            'double quote'   => ['The "Boss"', '"The \\"Boss\\"" <victim@example.com>'],
            'backslash'      => ['Back \\ Slash', '"Back \\\\ Slash" <victim@example.com>'],
            'angle brackets' => ['Name <x>', '"Name <x>" <victim@example.com>'],
            'at sign'        => ['team@work', '"team@work" <victim@example.com>'],
            'colon'          => ['Re: Team', '"Re: Team" <victim@example.com>'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonAsciiSpecialsNameProvider(): array
    {
        return [
            'comma'        => ['Jösé, Jr'],
            'double quote' => ['Jösé "Boss"'],
            'colon'        => ['Re: Jösé'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function plainNameProvider(): array
    {
        return [
            'words'                  => ['Example Person'],
            'initial with full stop' => ['John Q. Public'],
            'apostrophe'             => ["Pat O'Brien"],
        ];
    }

    private static function makeHeader(string $name): To
    {
        return new To(new AddressList(new Address('victim@example.com', $name)));
    }
}
