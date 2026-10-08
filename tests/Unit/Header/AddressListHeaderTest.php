<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Exception\InvalidArgumentException as MailInvalidArgumentException;
use Contenir\Mail\Header\AbstractAddressList;
use Contenir\Mail\Header\AddressEncoder;
use Contenir\Mail\Header\AddressListCodec;
use Contenir\Mail\Header\Bcc;
use Contenir\Mail\Header\Cc;
use Contenir\Mail\Header\Exception\InvalidArgumentException;
use Contenir\Mail\Header\From;
use Contenir\Mail\Header\HeaderWrap;
use Contenir\Mail\Header\ReplyTo;
use Contenir\Mail\Header\To;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function explode;
use function max;
use function str_repeat;
use function str_starts_with;
use function strlen;

#[CoversClass(AbstractAddressList::class)]
#[CoversClass(AddressListCodec::class)]
#[CoversClass(AddressEncoder::class)]
#[CoversClass(Bcc::class)]
#[CoversClass(Cc::class)]
#[CoversClass(From::class)]
#[CoversClass(ReplyTo::class)]
#[CoversClass(To::class)]
#[Group('unit')]
final class AddressListHeaderTest extends TestCase
{
    /** An address of 62 characters, long enough that the name and address together are measured */
    private const string LONG_EMAIL = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@example.com';

    private const string FIELD_VALUE =
        'Example Test <test@example.com>, list@example.com, '
            . 'Example Announce List <announce@example.com>, "Last, First" <first@last.example.com>';

    private const string ENCODED_FIELD_VALUE =
        "Example Test <test@example.com>,\r\n list@example.com,\r\n"
            . " Example Announce List <announce@example.com>,\r\n \"Last, First\" <first@last.example.com>";

    private const array EXPECTED_RECIPIENTS = [
        'test@example.com'       => 'Example Test',
        'list@example.com'       => null,
        'announce@example.com'   => 'Example Announce List',
        'first@last.example.com' => 'Last, First',
    ];

    /**
     * @param class-string<AbstractAddressList> $class
     */
    #[DataProvider('headerClassProvider')]
    #[Test]
    public function hasCanonicalFieldName(string $class, string $fieldName): void
    {
        static::assertSame($fieldName, (new $class())->getFieldName());
    }

    /**
     * @param class-string<AbstractAddressList> $class
     */
    #[DataProvider('headerClassProvider')]
    #[Test]
    public function startsWithEmptyAddressList(string $class): void
    {
        static::assertTrue((new $class())->getAddressList()->isEmpty());
    }

    /**
     * @param class-string<AbstractAddressList> $class
     */
    #[DataProvider('headerClassProvider')]
    #[Test]
    public function writesNoHeaderForEmptyList(string $class): void
    {
        static::assertSame('', (new $class())->toString());
    }

    #[Test]
    public function fieldValueIsEmptyByDefault(): void
    {
        static::assertSame('', (new To())->getFieldValue());
    }

    #[Test]
    public function encodedFieldValueIsEmptyByDefault(): void
    {
        static::assertSame('', (new To())->getEncodedFieldValue());
    }

    #[Test]
    public function keepsGivenAddressList(): void
    {
        $list = self::makeAddressList();

        static::assertSame($list->toArray(), (new To($list))->getAddressList()->toArray());
    }

    #[Test]
    public function fieldValueIsCreatedFromAddressList(): void
    {
        static::assertSame(self::FIELD_VALUE, (new To(self::makeAddressList()))->getFieldValue());
    }

    #[Test]
    public function foldsEncodedFieldValueAfterEachAddress(): void
    {
        static::assertSame(self::ENCODED_FIELD_VALUE, (new To(self::makeAddressList()))->getEncodedFieldValue());
    }

    /**
     * @param class-string<AbstractAddressList> $class
     */
    #[DataProvider('headerClassProvider')]
    #[Test]
    public function stringRepresentationIncludesHeaderAndFieldValue(string $class, string $fieldName): void
    {
        static::assertSame(
            "{$fieldName}: " . self::ENCODED_FIELD_VALUE,
            (new $class(self::makeAddressList()))->toString(),
        );
    }

    #[Test]
    public function withAddressListReturnsHeaderWithNewList(): void
    {
        $list = self::makeAddressList();

        static::assertSame(
            $list->toArray(),
            (new Cc())->withAddressList($list)
                ->getAddressList()
                ->toArray(),
        );
    }

    #[Test]
    public function withAddressListKeepsHeaderType(): void
    {
        static::assertInstanceOf(Cc::class, (new Cc())->withAddressList(self::makeAddressList()));
    }

    #[Test]
    public function withAddressListLeavesOriginalUnchanged(): void
    {
        $header = new Cc();
        static::assertNotSame($header, $header->withAddressList(self::makeAddressList()));

        static::assertTrue($header->getAddressList()->isEmpty());
    }

    /**
     * @param class-string<AbstractAddressList> $class
     */
    #[DataProvider('headerClassProvider')]
    #[Test]
    public function parsesOwnHeaderLine(string $class, string $fieldName): void
    {
        static::assertInstanceOf($class, $class::fromString("{$fieldName}: " . self::FIELD_VALUE));
    }

    /**
     * @param class-string<AbstractAddressList> $class
     */
    #[DataProvider('headerClassProvider')]
    #[Test]
    public function readsAddressesFromHeaderLine(string $class, string $fieldName): void
    {
        $list = $class::fromString("{$fieldName}: " . self::FIELD_VALUE)->getAddressList();

        static::assertSame(self::EXPECTED_RECIPIENTS, self::recipients($list));
    }

    /**
     * @param class-string<AbstractAddressList> $class
     */
    #[DataProvider('headerClassProvider')]
    #[Group('3789')]
    #[Test]
    public function allowsNoWhitespaceBetweenHeaderAndValue(string $class, string $fieldName): void
    {
        $list = $class::fromString("{$fieldName}:" . self::FIELD_VALUE)->getAddressList();

        static::assertSame(self::EXPECTED_RECIPIENTS, self::recipients($list));
    }

    /**
     * @param class-string<AbstractAddressList> $class
     */
    #[DataProvider('headerClassProvider')]
    #[Test]
    public function rejectsHeaderLineOfAnotherHeader(string $class, string $fieldName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid header line for \"{$fieldName}\" string");

        $class::fromString('Subject: test@example.com');
    }

    /**
     * @param array<string, ?string> $expected
     */
    #[DataProvider('commentProvider')]
    #[Test]
    public function ignoresCommentsAroundAddress(string $headerLine, array $expected): void
    {
        static::assertSame($expected, self::recipients(From::fromString($headerLine)->getAddressList()));
    }

    #[Test]
    public function keepsCommentOnAddress(): void
    {
        static::assertSame(
            'Comment',
            From::fromString('From: user@example.com (Comment)')->getAddressList()->first()?->getComment(),
        );
    }

    #[Test]
    public function unfoldsDisplayNameFoldedOverLines(): void
    {
        static::assertSame(
            'John Doe',
            From::fromString("From: \"John\r\n Doe\" <john@example.com>")->getAddressList()->first()?->getName(),
        );
    }

    /**
     * Text after the closing angle bracket is not part of a name-addr, so the entry is read as a bare address.
     */
    #[Test]
    public function refusesTextAfterAngleAddress(): void
    {
        $this->expectException(MailInvalidArgumentException::class);
        $this->expectExceptionMessage("'example.com> trailing' is not a valid hostname for the email address");

        From::fromString('From: Name <user@example.com> trailing');
    }

    #[Test]
    public function joinsCommentsOnBothSidesOfAddress(): void
    {
        static::assertSame(
            'First, Second',
            From::fromString('From: (First)user@example.com(Second)')->getAddressList()->first()?->getComment(),
        );
    }

    #[DataProvider('surroundingSingleQuotesProvider')]
    #[Test]
    public function trimsSurroundingSingleQuotes(string $headerLine): void
    {
        static::assertSame(
            ['foo@example.com'],
            array_map(
                static fn(Address $address): string => $address->getEmail(),
                To::fromString($headerLine)->getAddressList()->toArray(),
            ),
        );
    }

    /**
     * @param array<string, ?string> $expected
     */
    #[DataProvider('groupProvider')]
    #[Test]
    public function flattensGroupsIntoTheirMembers(string $headerLine, array $expected): void
    {
        static::assertSame($expected, self::recipients(To::fromString($headerLine)->getAddressList()));
    }

    /**
     * @param array<string, ?string> $expected
     */
    #[DataProvider('specialCharHeaderProvider')]
    #[Test]
    public function decodesSpecialCharactersInNames(string $headerLine, array $expected): void
    {
        static::assertSame($expected, self::recipients(To::fromString($headerLine)->getAddressList()));
    }

    #[DataProvider('unconventionalHeaderLinesProvider')]
    #[Test]
    public function acceptsUnconventionalReplyToNames(string $headerLine): void
    {
        static::assertSame('test@example.com', ReplyTo::fromString($headerLine)->getFieldValue());
    }

    #[DataProvider('unconventionalHeaderLinesProvider')]
    #[Test]
    public function writesCanonicalReplyToName(string $headerLine): void
    {
        static::assertSame('Reply-To', ReplyTo::fromString($headerLine)->getFieldName());
    }

    #[DataProvider('encodedAddressProvider')]
    #[Test]
    public function encodesAddressForTheWire(Address $address, string $expected): void
    {
        static::assertSame($expected, (new To(new AddressList($address)))->toString());
    }

    #[DataProvider('encodedAddressProvider')]
    #[Test]
    public function codecEncodesSingleAddress(Address $address, string $expected): void
    {
        static::assertSame($expected, 'To: ' . AddressEncoder::encode($address));
    }

    #[Test]
    public function fieldValueKeepsNonAsciiDomainAndName(): void
    {
        $header = new To(new AddressList(new Address('local-part@ä-umlaut.de', 'Jösé')));

        static::assertSame('Jösé <local-part@ä-umlaut.de>', $header->getFieldValue());
    }

    #[Test]
    public function decodesRfc2047EncodedName(): void
    {
        $list = To::fromString('To: =?UTF-8?Q?J=C3=B6s=C3=A9?= <jose@example.com>')->getAddressList();

        static::assertSame(['jose@example.com' => 'Jösé'], self::recipients($list));
    }

    #[Test]
    public function decodesFoldedHeaderLine(): void
    {
        $list = AddressListCodec::decode("one@example.com,\r\n two@example.com");

        static::assertSame(['one@example.com' => null, 'two@example.com' => null], self::recipients($list));
    }

    #[Test]
    public function decodesEmptyValueAsEmptyList(): void
    {
        static::assertTrue(AddressListCodec::decode('')->isEmpty());
    }

    /**
     * @return array<string, array{class-string<AbstractAddressList>, string}>
     */
    public static function headerClassProvider(): array
    {
        return [
            'Bcc'      => [Bcc::class, 'Bcc'],
            'Cc'       => [Cc::class, 'Cc'],
            'From'     => [From::class, 'From'],
            'Reply-To' => [ReplyTo::class, 'Reply-To'],
            'To'       => [To::class, 'To'],
        ];
    }

    /**
     * @return array<string, array{string, array<string, ?string>}>
     */
    public static function commentProvider(): array
    {
        return [
            'comment after address'       => ['From: user@example.com (Comment)', ['user@example.com' => null]],
            'escaped paren in comment'    => ['From: user@example.com (Comm\\)ent)', ['user@example.com' => null]],
            'comments on both sides'      => [
                'From: (Comment\\\\)user@example.com(Another)',
                ['user@example.com' => null],
            ],
            'comment after named address' => [
                'From: Example User <user@example.com> (work)',
                ['user@example.com' => 'Example User'],
            ],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function surroundingSingleQuotesProvider(): array
    {
        return [
            'inside angle brackets'      => ["To: <'foo@example.com'>"],
            'inside named angle bracket' => ["To: Foo Bar <'foo@example.com'>"],
            'bare'                       => ["To: 'foo@example.com'"],
        ];
    }

    /**
     * @return array<string, array{string, array<string, ?string>}>
     */
    public static function groupProvider(): array
    {
        return [
            'empty group'             => ['To: undisclosed-recipients:;', []],
            'two groups'              => [
                'To: friends: john@example.com; enemies: john@example.net, bart@example.net;',
                ['john@example.com' => null, 'john@example.net' => null, 'bart@example.net' => null],
            ],
            'group with named member' => [
                'To: Team: Jo <jo@example.com>, al@example.com;',
                ['jo@example.com' => 'Jo', 'al@example.com' => null],
            ],
            'address after a group'   => [
                'To: Team: one@example.com;, solo@example.com',
                ['one@example.com' => null, 'solo@example.com' => null],
            ],
        ];
    }

    /**
     * @return array<string, array{string, array<string, ?string>}>
     */
    public static function specialCharHeaderProvider(): array
    {
        return [
            'RFC 2047 name with a comma'         => [
                'To: =?UTF-8?B?dGVzdCxsYWJlbA==?= <john@example.com>, john2@example.com',
                ['john@example.com' => 'test,label', 'john2@example.com' => null],
            ],
            'quoted name with escaped quote'     => [
                'To: "TEST\",QUOTE" <john@example.com>, john2@example.com',
                ['john@example.com' => 'TEST",QUOTE', 'john2@example.com' => null],
            ],
            'quoted name with escaped backslash' => [
                'To: "Back \\\\ Slash" <john@example.com>',
                ['john@example.com' => 'Back \\ Slash'],
            ],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unconventionalHeaderLinesProvider(): array
    {
        return [
            'replyto'  => ['ReplyTo: test@example.com'],
            'reply_to' => ['Reply_To: test@example.com'],
            'reply-to' => ['reply-to: test@example.com'],
        ];
    }

    /**
     * @return array<string, array{Address, string}>
     */
    public static function encodedAddressProvider(): array
    {
        return [
            'bare ASCII address'             => [new Address('test@example.com'), 'To: test@example.com'],
            'ASCII name is not encoded'      => [
                new Address('test@example.com', 'Example Test'),
                'To: Example Test <test@example.com>',
            ],
            'ASCII name with specials'       => [
                new Address('test@example.com', 'Last, First'),
                'To: "Last, First" <test@example.com>',
            ],
            'non-ASCII name'                 => [
                new Address('test@example.com', 'Jösé'),
                'To: =?UTF-8?Q?J=C3=B6s=C3=A9?= <test@example.com>',
            ],
            'IDN domain becomes punycode'    => [
                new Address('local-part@ä-umlaut.de'),
                'To: local-part@xn---umlaut-4wa.de',
            ],
            'IDN domain with non-ASCII name' => [
                new Address('local-part@ä-umlaut.de', 'Jösé'),
                'To: =?UTF-8?Q?J=C3=B6s=C3=A9?= <local-part@xn---umlaut-4wa.de>',
            ],
        ];
    }

    private static function makeAddressList(): AddressList
    {
        return new AddressList(
            new Address('test@example.com', 'Example Test'),
            new Address('list@example.com'),
            new Address('announce@example.com', 'Example Announce List'),
            new Address('first@last.example.com', 'Last, First'),
        );
    }

    /**
     * @return array<string, ?string>
     */
    private static function recipients(AddressList $list): array
    {
        $recipients = [];
        foreach ($list as $address) {
            $recipients[$address->getEmail()] = $address->getName();
        }

        return $recipients;
    }

    #[Test]
    public function keepsAsciiDomainCaseOnTheWire(): void
    {
        static::assertSame('User@Example.COM', AddressEncoder::encode(new Address('User@Example.COM')));
    }

    /**
     * "Reply-To: ", the name, the address in angle brackets and "," make a line of exactly 998.
     */
    #[Test]
    public function quotesLongDisplayNameThatFitsLineLimit(): void
    {
        $name = str_repeat('a', times: 922);

        static::assertSame(
            "{$name} <" . self::LONG_EMAIL . '>',
            (new ReplyTo(new AddressList(new Address(self::LONG_EMAIL, $name))))->getEncodedFieldValue(),
        );
    }

    /**
     * A display name can be encoded, so one too long for a line is written as encoded words.
     */
    #[Test]
    public function encodesDisplayNameTooLongForLineLimit(): void
    {
        $name    = str_repeat('a', times: 923);
        $encoded = (new ReplyTo(new AddressList(new Address(self::LONG_EMAIL, $name))))->getEncodedFieldValue();

        static::assertSame(
            ["{$name} <" . self::LONG_EMAIL . '>', true, true],
            [
                HeaderWrap::mimeDecodeValue($encoded),
                str_starts_with($encoded, '=?UTF-8?Q?'),
                max(array_map(strlen(...), explode("\r\n", "Reply-To: {$encoded}"))) <= 998,
            ],
        );
    }

    /**
     * @param array{string|null, string, string|null} $expected
     */
    #[DataProvider('foldedAddressProvider')]
    #[Test]
    public function unfoldsNameAndCommentAcrossFolds(string $headerLine, array $expected): void
    {
        $address = To::fromString($headerLine)->getAddressList()->first();

        static::assertSame($expected, [$address?->getName(), $address?->getEmail(), $address?->getComment()]);
    }

    /**
     * @return array<string, array{string, array{string|null, string, string|null}}>
     */
    public static function foldedAddressProvider(): array
    {
        return [
            'comment folded with space' => [
                "To: a@example.com (work\r\n place)",
                [null, 'a@example.com', 'work place'],
            ],
            'comment folded with tab'   => [
                "To: a@example.com (work\r\n\tplace)",
                [null, 'a@example.com', 'work place'],
            ],
            'name folded with tab'      => [
                "To: \"John\r\n\tSmith\" <j@example.com>",
                ['John Smith', 'j@example.com', null],
            ],
            'fold before address'       => [
                "To: John Smith\r\n <j@example.com>",
                ['John Smith', 'j@example.com', null],
            ],
        ];
    }
}
