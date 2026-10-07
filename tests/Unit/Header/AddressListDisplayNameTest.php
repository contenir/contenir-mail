<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\AbstractAddressList;
use Contenir\Mail\Header\HeaderInterface;
use Contenir\Mail\Header\To;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractAddressList::class)]
#[Group('unit')]
final class AddressListDisplayNameTest extends TestCase
{
    #[DataProvider('hostileNameProvider')]
    #[Test]
    public function displayNameCannotAddRecipientsWhenReparsed(string $name, string $encoding): void
    {
        $reparsed = To::fromString($this->makeHeader($name, $encoding)->toString());

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
            $this->makeHeader($name, 'ASCII')->getFieldValue(HeaderInterface::FORMAT_ENCODED),
        );
    }

    #[DataProvider('plainNameProvider')]
    #[Test]
    public function leavesDisplayNameWithoutSpecialsUnquoted(string $name): void
    {
        static::assertSame(
            "{$name} <victim@example.com>",
            $this->makeHeader($name, 'ASCII')->getFieldValue(HeaderInterface::FORMAT_ENCODED),
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
     * @return array<string, array{string, string}>
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
            $cases["{$label} (ASCII)"] = [$name, 'ASCII'];
            $cases["{$label} (UTF-8)"] = [$name, 'UTF-8'];
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
    public static function plainNameProvider(): array
    {
        return [
            'words'                  => ['Example Person'],
            'initial with full stop' => ['John Q. Public'],
            'apostrophe'             => ["Pat O'Brien"],
        ];
    }

    private function makeHeader(string $name, string $encoding): To
    {
        $header = new To();
        $header->setEncoding($encoding);
        $header->getAddressList()->add('victim@example.com', $name);

        return $header;
    }
}
