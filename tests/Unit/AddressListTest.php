<?php

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Header;
use Countable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Traversable;

use function count;

#[CoversClass(\Contenir\Mail\AddressList::class)]
class AddressListTest extends TestCase
{
    private AddressList $list;

    public function setUp(): void
    {
        $this->list = new AddressList();
    }

    #[Test]
    public function implementsCountable(): void
    {
        static::assertInstanceOf(Countable::class, $this->list);
    }

    #[Test]
    public function isEmptyByDefault(): void
    {
        static::assertEquals(0, count($this->list));
    }

    #[Test]
    public function addingEmailsIncreasesCount(): void
    {
        $this->list->add('test@example.com');
        static::assertEquals(1, count($this->list));
    }

    #[Test]
    public function addingEmailFromStringIncreasesCount(): void
    {
        $this->list->addFromString('test@example.com');
        static::assertEquals(1, count($this->list));
    }

    #[Test]
    public function implementsTraversable(): void
    {
        static::assertInstanceOf(Traversable::class, $this->list);
    }

    #[Test]
    public function hasReturnsFalseWhenAddressNotInList(): void
    {
        static::assertFalse($this->list->has('foo@example.com'));
    }

    #[Test]
    public function hasReturnsTrueWhenAddressInList(): void
    {
        $this->list->add('test@example.com');
        static::assertTrue($this->list->has('test@example.com'));
    }

    #[Test]
    public function getReturnsFalseWhenEmailNotFound(): void
    {
        static::assertFalse($this->list->get('foo@example.com'));
    }

    #[Test]
    public function throwExceptionOnInvalidInputAdd(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('add expects an email address or Contenir\Mail\Address object');
        $this->list->add(null);
    }

    #[Test]
    public function throwExceptionOnInvalidInputAddMany(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('add expects an email address or Contenir\Mail\Address object');
        $this->list->addMany([null]);
    }

    #[Test]
    public function getReturnsAddressObjectWhenEmailFound(): void
    {
        $this->list->add('test@example.com');
        $address = $this->list->get('test@example.com');
        static::assertInstanceOf(Address::class, $address);
        static::assertEquals('test@example.com', $address->getEmail());
    }

    #[Test]
    public function canAddAddressWithName(): void
    {
        $this->list->add('test@example.com', 'Example Test');
        $address = $this->list->get('test@example.com');
        static::assertInstanceOf(Address::class, $address);
        static::assertEquals('test@example.com', $address->getEmail());
        static::assertEquals('Example Test', $address->getName());
    }

    #[Test]
    public function canAddManyAddressesAtOnce(): void
    {
        $addresses = [
            'test@example.com',
            'list@example.com' => 'Example List',
            new Address('announce@example.com', 'Announce List'),
        ];
        $this->list->addMany($addresses);
        static::assertEquals(3, count($this->list));
        static::assertTrue($this->list->has('test@example.com'));
        static::assertTrue($this->list->has('list@example.com'));
        static::assertTrue($this->list->has('announce@example.com'));
    }

    #[Test]
    public function canAddFromStringFluently(): void
    {
        $this->list
            ->addFromString('test_fromstring_fluency1@example.com')
            ->addFromString('test_fromstring_fluency2@example.com');

        static::assertTrue($this->list->has('test_fromstring_fluency1@example.com'));
        static::assertTrue($this->list->has('test_fromstring_fluency2@example.com'));
    }

    #[Test]
    public function losesParensInName(): void
    {
        $header = '"Supports (E-mail)" <support@example.org>';

        $to          = Header\To::fromString('To:' . $header);
        $addressList = $to->getAddressList();
        $address     = $addressList->get('support@example.org');
        static::assertEquals('Supports', $address->getName());
        static::assertEquals('E-mail', $address->getComment());
        static::assertEquals('support@example.org', $address->getEmail());
    }

    #[Test]
    public function doesNotStoreDuplicatesAndFirstWins(): void
    {
        $addresses = [
            'test@example.com',
            new Address('test@example.com', 'Example Test'),
        ];
        $this->list->addMany($addresses);
        static::assertEquals(1, count($this->list));
        static::assertTrue($this->list->has('test@example.com'));
        $address = $this->list->get('test@example.com');
        static::assertNull($address->getName());
    }

    /**
     * Microsoft Outlook sends emails with semicolon separated To addresses.
     *
     * @see https://blogs.msdn.microsoft.com/oldnewthing/20150119-00/?p=44883
     */
    #[Test]
    public function semicolonSeparator(): void
    {
        $header =
            'Some User <some.user@example.com>; uzer2.surname@example.org;'
            . ' asda.fasd@example.net, root@example.org';

        // In previous versions, this throws: 'The input exceeds the allowed
        // length'; hence the try/catch block, to allow finding the root cause.
        try {
            $to = Header\To::fromString('To:' . $header);
        } catch (InvalidArgumentException) {
            static::fail('Header\To::fromString should not throw');
        }
        $addressList = $to->getAddressList();

        static::assertEquals('Some User', $addressList->get('some.user@example.com')->getName());
        static::assertTrue($addressList->has('uzer2.surname@example.org'));
        static::assertTrue($addressList->has('asda.fasd@example.net'));
        static::assertTrue($addressList->has('root@example.org'));
    }

    #[Test]
    public function mergeTwoLists(): void
    {
        $otherList = new AddressList();
        $this->list->add('one@example.net');
        $otherList->add('two@example.org');
        $this->list->merge($otherList);
        static::assertEquals(2, count($this->list));
    }

    #[Test]
    public function deleteSuccess(): void
    {
        $this->list->add('test@example.com');
        static::assertTrue($this->list->delete('test@example.com'));
        static::assertEquals(0, count($this->list));
    }

    #[Test]
    public function deleteNotExist(): void
    {
        static::assertFalse($this->list->delete('test@example.com'));
    }

    #[Test]
    public function key(): void
    {
        static::assertNull($this->list->key());
        $this->list->add('test@example.com');
        $this->list->add('test@example.net');
        $this->list->add('test@example.org');
        $this->list->rewind();
        static::assertSame('test@example.com', $this->list->key());
        $this->list->next();
        static::assertSame('test@example.net', $this->list->key());
        $this->list->next();
        static::assertSame('test@example.org', $this->list->key());
    }

    /**
     * If name-field is quoted with "", then ' inside it should not treated as terminator, but as value.
     */
    #[Test]
    public function mixedQuotesInName(): void
    {
        $header = '"Bob O\'Reilly" <bob@example.com>,blah@example.com';

        // In previous versions, this throws:
        // 'Bob O'Reilly <bob@example.com>,blah' can not be matched against dot-atom format
        // hence the try/catch block, to allow finding the root cause.
        try {
            $to = Header\To::fromString('To:' . $header);
        } catch (InvalidArgumentException) {
            static::fail('Header\To::fromString should not throw');
        }

        $addressList = $to->getAddressList();
        static::assertTrue($addressList->has('bob@example.com'));
        static::assertTrue($addressList->has('blah@example.com'));
        static::assertEquals("Bob O'Reilly", $addressList->get('bob@example.com')->getName());
    }
}
