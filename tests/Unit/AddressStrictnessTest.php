<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Header\SafeText;
use Contenir\Mail\Header\To;
use Contenir\Mail\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function implode;

/**
 * Addresses real mail servers take but RFC 5322 refuses, accepted only when
 * asked for with new Address(..., strict: false) (contenir/contenir-mail#18).
 */
#[CoversClass(Address::class)]
#[CoversClass(SafeText::class)]
#[Group('unit')]
final class AddressStrictnessTest extends TestCase
{
    #[Test]
    #[DataProvider('serverAddressProvider')]
    public function acceptsAnAddressServersTake(string $email): void
    {
        static::assertSame($email, (new Address($email, strict: false))->getEmail());
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
        $address = new Address(' jo.@example.org ', 'Jo Bloggs', 'work', strict: false);

        static::assertSame(
            ['jo.@example.org', 'Jo Bloggs', 'work'],
            [$address->getEmail(), $address->getName(), $address->getComment()],
        );
    }

    #[Test]
    public function acceptsAnInternationalisedDomainThatConvertsToAscii(): void
    {
        static::assertSame('jo.@bücher.example', (new Address('jo.@bücher.example', strict: false))->getEmail());
    }

    #[Test]
    public function writesTheAddressIntoTheHeaderAsGiven(): void
    {
        $message = (new Message())->setTo(new Address('jo..bloggs@example.org', 'Jo', strict: false));

        static::assertSame(
            'Jo <jo..bloggs@example.org>',
            $message->getHeaders()->get('To')?->getFieldValue(),
        );
    }

    #[Test]
    public function isStrictByDefault(): void
    {
        static::assertSame(
            [true, false],
            [(new Address('jo@example.org'))->isStrict(), (new Address('jo@example.org', strict: false))->isStrict()],
        );
    }

    /**
     * Operations on messages, headers and lists keep an address as it was built, without checking it again.
     *
     * @param callable(Address): string $use Returns the To field value, or the addresses, the operation gives.
     */
    #[Test]
    #[DataProvider('operationProvider')]
    public function keepsALenientAddressThroughEveryOperation(callable $use, string $expected): void
    {
        static::assertSame($expected, $use(new Address('jo..bloggs@example.org', 'Jo', strict: false)));
    }

    /**
     * @return array<string, array{callable(Address): string, string}>
     */
    public static function operationProvider(): array
    {
        $to   = static fn(Message $message): string => (string) $message->getHeaders()->get('To')?->getFieldValue();
        $list = static fn(AddressList $addresses): string => implode(', ', array_map(
            static fn(Address $address): string => $address->toString(),
            $addresses->toArray(),
        ));

        return [
            'Message::setTo()'            => [
                static fn(Address $address): string => $to((new Message())->setTo($address)),
                'Jo <jo..bloggs@example.org>',
            ],
            'Message::addTo()'            => [
                static fn(Address $address): string => $to(
                    (new Message())->addTo('sam@example.org')
                        ->addTo($address),
                ),
                'sam@example.org, Jo <jo..bloggs@example.org>',
            ],
            'Message::addCc()'            => [
                static fn(Address $address): string => $list(
                    (new Message())->addCc($address)
                        ->getCc(),
                ),
                'Jo <jo..bloggs@example.org>',
            ],
            'Message::addBcc()'           => [
                static fn(Address $address): string => $list(
                    (new Message())->addBcc($address)
                        ->getBcc(),
                ),
                'Jo <jo..bloggs@example.org>',
            ],
            'AddressList::with()'         => [
                static fn(Address $address): string => $list((new AddressList(new Address('sam@example.org')))->with(
                    $address,
                )),
                'sam@example.org, Jo <jo..bloggs@example.org>',
            ],
            'AddressList::withList()'     => [
                static fn(Address $address): string => $list((new AddressList())->withList(new AddressList($address))),
                'Jo <jo..bloggs@example.org>',
            ],
            'AddressList::fromIterable()' => [
                static fn(Address $address): string => $list(AddressList::fromIterable([$address])),
                'Jo <jo..bloggs@example.org>',
            ],
            'header withAdded()'          => [
                static fn(Address $address): string => (new To(new Address('sam@example.org')))->withAdded($address)
                    ->getFieldValue(),
                'sam@example.org, Jo <jo..bloggs@example.org>',
            ],
            'header withAddressList()'    => [
                static fn(Address $address): string => (new To())->withAddressList(new AddressList($address))
                    ->getFieldValue(),
                'Jo <jo..bloggs@example.org>',
            ],
            'SafeText::addressList()'     => [
                static fn(Address $address): string => $list(SafeText::addressList(new AddressList($address))),
                'Jo <jo..bloggs@example.org>',
            ],
        ];
    }

    #[Test]
    #[DataProvider('unusableAddressProvider')]
    public function refusesWhatCouldBreakAHeaderOrCommand(string $email, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Address($email, strict: false);
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
