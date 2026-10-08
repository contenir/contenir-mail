<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Address;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Addresses real mail servers take but RFC 5322 refuses, accepted only when
 * asked for with Address::lenient() (contenir/contenir-mail#18).
 */
#[CoversClass(Address::class)]
#[Group('unit')]
final class AddressLenientTest extends TestCase
{
    #[Test]
    #[DataProvider('serverAddressProvider')]
    public function acceptsAnAddressServersTake(string $email): void
    {
        static::assertSame($email, Address::lenient($email)->getEmail());
    }

    #[Test]
    #[DataProvider('serverAddressProvider')]
    public function stillRefusesItWithoutBeingAsked(string $email): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Address($email);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function serverAddressProvider(): array
    {
        return [
            'consecutive dots'       => ['jo..bloggs@example.org'],
            'trailing dot'           => ['jo.@example.org'],
            'leading dot'            => ['.jo@example.org'],
            'underscore in the host' => ['jo@mail_server.example'],
        ];
    }

    #[Test]
    public function trimsTheAddressAndKeepsItsNameAndComment(): void
    {
        $address = Address::lenient(' jo.@example.org ', 'Jo Bloggs', 'work');

        static::assertSame(
            ['jo.@example.org', 'Jo Bloggs', 'work'],
            [$address->getEmail(), $address->getName(), $address->getComment()],
        );
    }

    #[Test]
    public function acceptsAnInternationalisedDomainThatConvertsToAscii(): void
    {
        static::assertSame('jo.@bücher.example', Address::lenient('jo.@bücher.example')->getEmail());
    }

    #[Test]
    public function writesTheAddressIntoTheHeaderAsGiven(): void
    {
        $message = (new Message())->setTo(Address::lenient('jo..bloggs@example.org', 'Jo'));

        static::assertSame(
            'Jo <jo..bloggs@example.org>',
            $message->getHeaders()->get('To')?->getFieldValue(),
        );
    }

    #[Test]
    #[DataProvider('unusableAddressProvider')]
    public function refusesWhatCouldBreakAHeaderOrCommand(string $email, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Address::lenient($email);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unusableAddressProvider(): array
    {
        $structure = 'An address needs one "@" and a domain, without whitespace or the characters <>()[],;:"\\';

        return [
            'no at sign'                 => ['jo.example.org', $structure],
            'two at signs'               => ['jo@@example.org', $structure],
            'no local part'              => ['@example.org', $structure],
            'no domain'                  => ['jo@', $structure],
            'a space'                    => ['jo bloggs@example.org', $structure],
            'a tab'                      => ["jo\tbloggs@example.org", $structure],
            'a comma'                    => ['jo,sam@example.org', $structure],
            'a semicolon'                => ['jo;sam@example.org', $structure],
            'a colon'                    => ['team:jo@example.org', $structure],
            'angle brackets'             => ['<jo@example.org>', $structure],
            'parentheses'                => ['jo(work)@example.org', $structure],
            'square brackets'            => ['jo@[127.0.0.1]', $structure],
            'a quote'                    => ['"jo"@example.org', $structure],
            'a backslash'                => ['jo\\x@example.org', $structure],
            'a line break'               => ["jo@example.org\r\nBcc: x@evil.example", 'CRLF injection detected'],
            'a control character'        => ["jo\x1B@example.org", 'Address must not contain control characters'],
            'a bidirectional override'   => [
                "jo\u{202E}@example.org",
                'Address must not contain bidirectional overrides',
            ],
            'invalid UTF-8'              => ["jo\xFF@example.org", 'Address must be UTF-8 text'],
            'empty'                      => [' ', 'Email must be a valid email address'],
            'a domain IDNA cannot write' => [
                "jo@a\u{200D}b.example",
                'The domain a‍b.example cannot be written as ASCII',
            ],
        ];
    }
}
