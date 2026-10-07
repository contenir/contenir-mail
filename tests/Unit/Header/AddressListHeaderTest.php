<?php

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Header\AbstractAddressList;
use Contenir\Mail\Header\Bcc;
use Contenir\Mail\Header\Cc;
use Contenir\Mail\Header\From;
use Contenir\Mail\Header\ReplyTo;
use Contenir\Mail\Header\To;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function sprintf;

class AddressListHeaderTest extends TestCase
{
    public static function getHeaderInstances(): array
    {
        return [
            [new Bcc(), 'Bcc'],
            [new Cc(), 'Cc'],
            [new From(), 'From'],
            [new ReplyTo(), 'Reply-To'],
            [new To(), 'To'],
        ];
    }

    #[Test]
    #[DataProvider('getHeaderInstances')]
    public function concreteHeadersExtendAbstractAddressListHeader(AbstractAddressList $header): void
    {
        static::assertInstanceOf(AbstractAddressList::class, $header);
    }

    #[Test]
    #[DataProvider('getHeaderInstances')]
    public function concreteHeaderFieldNamesAreDiscrete(AbstractAddressList $header, string $type): void
    {
        static::assertSame($type, $header->getFieldName());
    }

    #[Test]
    #[DataProvider('getHeaderInstances')]
    public function concreteHeadersComposeAddressLists(AbstractAddressList $header): void
    {
        $list = $header->getAddressList();
        static::assertInstanceOf(AddressList::class, $list);
    }

    #[Test]
    public function fieldValueIsEmptyByDefault(): void
    {
        $header = new To();
        static::assertSame('', $header->getFieldValue());
    }

    #[Test]
    public function fieldValueIsCreatedFromAddressList(): void
    {
        $header = new To();
        $list   = $header->getAddressList();
        $this->populateAddressList($list);
        $expected = self::getExpectedFieldValue();
        static::assertSame($expected, $header->getFieldValue());
    }

    public function populateAddressList(AddressList $list): void
    {
        $address = new Address('test@example.com', 'Example Test');
        $list->add($address);
        $list->add('list@example.com');
        $list->add('announce@example.com', 'Example Announce List');
        $list->add('first@last.example.com', 'Last, First');
    }

    public static function getExpectedFieldValue(): string
    {
        // @codingStandardsIgnoreStart
        return "Example Test <test@example.com>,\r\n list@example.com,\r\n Example Announce List <announce@example.com>,\r\n \"Last, First\" <first@last.example.com>";

        // @codingStandardsIgnoreEnd
    }

    #[Test]
    #[DataProvider('getHeaderInstances')]
    public function stringRepresentationIncludesHeaderAndFieldValue(AbstractAddressList $header, string $type): void
    {
        $this->populateAddressList($header->getAddressList());
        $expected = sprintf('%s: %s', $type, self::getExpectedFieldValue());
        static::assertSame($expected, $header->toString());
    }

    public static function getStringHeaders(): array
    {
        $value = self::getExpectedFieldValue();
        return [
            'cc'       => ["Cc: {$value}", Cc::class],
            'bcc'      => ["Bcc: {$value}", Bcc::class],
            'from'     => ["From: {$value}", From::class],
            'reply-to' => ["Reply-To: {$value}", ReplyTo::class],
            'to'       => ["To: {$value}", To::class],
        ];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('getStringHeaders')]
    public function deserializationFromString(string $headerLine, string $class): void
    {
        $callback = sprintf('%s::fromString', $class);
        $header   = $callback($headerLine);
        static::assertInstanceOf($class, $header);
        $list = $header->getAddressList();
        static::assertSame(4, count($list));
        static::assertTrue($list->has('test@example.com'));
        static::assertTrue($list->has('list@example.com'));
        static::assertTrue($list->has('announce@example.com'));
        static::assertTrue($list->has('first@last.example.com'));
        $address = $list->get('test@example.com');
        static::assertSame('Example Test', $address->getName());
        $address = $list->get('list@example.com');
        static::assertNull($address->getName());
        $address = $list->get('announce@example.com');
        static::assertSame('Example Announce List', $address->getName());
        $address = $list->get('first@last.example.com');
        static::assertSame('Last, First', $address->getName());
    }

    public static function getStringHeadersWithNoWhitespaceSeparator(): array
    {
        $value = self::getExpectedFieldValue();
        return [
            'cc'       => ["Cc:{$value}", Cc::class],
            'bcc'      => ["Bcc:{$value}", Bcc::class],
            'from'     => ["From:{$value}", From::class],
            'reply-to' => ["Reply-To:{$value}", ReplyTo::class],
            'to'       => ["To:{$value}", To::class],
        ];
    }

    #[Test]
    #[DataProvider('getHeadersWithComments')]
    public function deserializationFromStringWithComments(string $value): void
    {
        $header = From::fromString($value);
        $list   = $header->getAddressList();
        static::assertSame(1, count($list));
        static::assertTrue($list->has('user@example.com'));
    }

    public static function getHeadersWithComments(): array
    {
        return [
            ['From: user@example.com (Comment)'],
            ['From: user@example.com (Comm\\)ent)'],
            ['From: (Comment\\\\)user@example.com(Another)'],
        ];
    }

    #[Test]
    #[DataProvider('getHeadersWithSurroundingSingleQuotes')]
    public function trimSurroundingSingleQuotes(string $value): void
    {
        $header = To::fromString($value);
        $list   = $header->getAddressList();
        static::assertSame(1, count($list));
        static::assertTrue($list->has('foo@example.com'));
    }

    /**
     * @return string[][]
     */
    public static function getHeadersWithSurroundingSingleQuotes(): array
    {
        return [
            ['To: <\'foo@example.com\'>'],
            ['To: Foo Bar <\'foo@example.com\'>'],
            ['To: \'foo@example.com\''],
        ];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[Group('3789')]
    #[DataProvider('getStringHeadersWithNoWhitespaceSeparator')]
    public function allowsNoWhitespaceBetweenHeaderAndValue(string $headerLine, string $class): void
    {
        $callback = sprintf('%s::fromString', $class);
        $header   = $callback($headerLine);
        static::assertInstanceOf($class, $header);
        $list = $header->getAddressList();
        static::assertSame(4, count($list));
        static::assertTrue($list->has('test@example.com'));
        static::assertTrue($list->has('list@example.com'));
        static::assertTrue($list->has('announce@example.com'));
        static::assertTrue($list->has('first@last.example.com'));
        $address = $list->get('test@example.com');
        static::assertSame('Example Test', $address->getName());
        $address = $list->get('list@example.com');
        static::assertNull($address->getName());
        $address = $list->get('announce@example.com');
        static::assertSame('Example Announce List', $address->getName());
        $address = $list->get('first@last.example.com');
        static::assertSame('Last, First', $address->getName());
    }

    /**
     * @param null|string $sample
     */
    #[Test]
    #[DataProvider('getAddressListsWithGroup')]
    public function addressListWithGroup(string $input, int $count, $sample): void
    {
        $header = To::fromString($input);
        $list   = $header->getAddressList();
        static::assertSame($count, count($list));
        if ($count > 0) {
            static::assertTrue($list->has($sample));
        }
    }

    public static function getAddressListsWithGroup(): array
    {
        return [
            ['To: undisclosed-recipients:;',                                                0, null],
            ['To: friends: john@example.com; enemies: john@example.net, bart@example.net;', 3, 'john@example.net'],
        ];
    }

    public static function specialCharHeaderProvider(): array
    {
        return [
            [
                'To: =?UTF-8?B?dGVzdCxsYWJlbA==?= <john@example.com>, john2@example.com',
                ['john@example.com' => 'test,label', 'john2@example.com' => null],
                'UTF-8',
            ],
            [
                'To: "TEST\",QUOTE" <john@example.com>, john2@example.com',
                ['john@example.com' => 'TEST",QUOTE', 'john2@example.com' => null],
                'ASCII',
            ],
        ];
    }

    #[Test]
    #[DataProvider('specialCharHeaderProvider')]
    public function deserializationFromSpecialCharString(
        string $headerLine,
        array $expected,
        string $encoding,
    ): void {
        $header = To::fromString($headerLine);

        $expectedTo  = new To();
        $addressList = $expectedTo->getAddressList();
        $addressList->addMany($expected);
        $expectedTo->setEncoding($encoding);
        static::assertEquals($expectedTo, $header);
        foreach ($expected as $k => $v) {
            static::assertTrue($addressList->has($k));
            static::assertSame($addressList->get($k)->getName(), $v);
        }
    }

    public static function unconventionalHeaderLinesProvider(): array
    {
        return [
            // Description => [header line, expected]
            'replyto'  => ['ReplyTo: test@example.com', ReplyTo::class, 'test@example.com'],
            'reply_to' => ['Reply_To: test@example.com', ReplyTo::class, 'test@example.com'],
        ];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('unconventionalHeaderLinesProvider')]
    public function fromStringHandlesUnconventionalNames(string $headerLine, string $class, string $expected): void
    {
        $callback = sprintf('%s::fromString', $class);
        $header   = $callback($headerLine);
        static::assertInstanceOf($class, $header);
        static::assertSame('Reply-To', $header->getFieldName());
        static::assertSame($expected, $header->getFieldValue());
    }
}
