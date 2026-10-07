<?php

namespace Contenir\Mail\Tests\Unit;

use ArrayIterator;
use Contenir\Mail;
use Contenir\Mail\Header;
use Contenir\Mail\Header\Exception;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Header\GenericMultiHeader;
use Countable;
use Iterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

use function implode;

#[CoversClass(\Contenir\Mail\Headers::class)]
class HeadersTest extends TestCase
{
    #[Test]
    public function headersImplementsProperClasses(): void
    {
        $headers = new Mail\Headers();
        static::assertInstanceOf(Iterator::class, $headers);
        static::assertInstanceOf(Countable::class, $headers);
    }

    #[Test]
    public function headersFromStringFactoryCreatesSingleObject(): void
    {
        $headers = Mail\Headers::fromString('Fake: foo-bar');
        static::assertSame(1, $headers->count());

        $header = $headers->get('fake');
        static::assertInstanceOf(GenericHeader::class, $header);
        static::assertSame('Fake', $header->getFieldName());
        static::assertSame('foo-bar', $header->getFieldValue());
    }

    #[Test]
    public function headersFromStringFactoryHandlesMissingWhitespace(): void
    {
        $headers = Mail\Headers::fromString('Fake:foo-bar');
        static::assertSame(1, $headers->count());

        $header = $headers->get('fake');
        static::assertInstanceOf(GenericHeader::class, $header);
        static::assertSame('Fake', $header->getFieldName());
        static::assertSame('foo-bar', $header->getFieldValue());
    }

    #[Test]
    #[Group('6657')]
    public function headersFromStringFactoryCreatesSingleObjectWithContinuationLine(): void
    {
        $headers = Mail\Headers::fromString("Fake: foo-bar,\r\n      blah-blah");
        static::assertSame(1, $headers->count());

        $header = $headers->get('fake');
        static::assertInstanceOf(GenericHeader::class, $header);
        static::assertSame('Fake', $header->getFieldName());
        static::assertSame('foo-bar, blah-blah', $header->getFieldValue());
    }

    #[Test]
    public function headersFromStringFactoryCreatesSingleObjectWithHeaderBreakLine(): void
    {
        $headers = Mail\Headers::fromString("Fake: foo-bar\r\n\r\n");
        static::assertSame(1, $headers->count());

        $header = $headers->get('fake');
        static::assertInstanceOf(GenericHeader::class, $header);
        static::assertSame('Fake', $header->getFieldName());
        static::assertSame('foo-bar', $header->getFieldValue());
    }

    #[Test]
    public function headersFromStringFactoryThrowsExceptionOnMalformedHeaderLine(): void
    {
        $this->expectException(Mail\Exception\RuntimeException::class);
        $this->expectExceptionMessage('does not match');
        Mail\Headers::fromString("Fake = foo-bar\r\n\r\n");
    }

    #[Test]
    public function headersFromStringFactoryThrowsExceptionOnMalformedHeaderLines(): void
    {
        $this->expectException(Mail\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Malformed header detected');
        Mail\Headers::fromString("Fake: foo-bar\r\n\r\n\r\n\r\nAnother-Fake: boo-baz");
    }

    #[Test]
    public function headersFromStringFactoryCreatesMultipleObjects(): void
    {
        $headers = Mail\Headers::fromString("Fake: foo-bar\r\nAnother-Fake: boo-baz");
        static::assertSame(2, $headers->count());

        $header = $headers->get('fake');
        static::assertInstanceOf(GenericHeader::class, $header);
        static::assertSame('Fake', $header->getFieldName());
        static::assertSame('foo-bar', $header->getFieldValue());

        $header = $headers->get('anotherfake');
        static::assertInstanceOf(GenericHeader::class, $header);
        static::assertSame('Another-Fake', $header->getFieldName());
        static::assertSame('boo-baz', $header->getFieldValue());
    }

    #[Test]
    public function headersFromStringMultiHeaderWillAggregateLazyLoadedHeaders(): void
    {
        $headers = new Mail\Headers();
        $loader  = $headers->getHeaderLocator();
        $loader->add('foo', GenericMultiHeader::class);
        $headers->addHeaderLine('foo: bar1,bar2,bar3');
        $headers->forceLoading();
        static::assertSame(3, $headers->count());
    }

    #[Test]
    public function headersHasAndGetWorkProperly(): void
    {
        $headers = new Mail\Headers();
        $headers->addHeaders([
            $f = new Header\GenericHeader('Foo', 'bar'),
            new Header\GenericHeader('Baz', 'baz'),
        ]);
        static::assertFalse($headers->has('foobar'));
        static::assertTrue($headers->has('foo'));
        static::assertTrue($headers->has('Foo'));
        static::assertSame('bar', $headers->get('foo')->getFieldValue());
    }

    #[Test]
    public function headersAggregatesHeaderObjects(): void
    {
        $fakeHeader = new Header\GenericHeader('Fake', 'bar');
        $headers    = new Mail\Headers();
        $headers->addHeader($fakeHeader);
        static::assertSame(1, $headers->count());
        static::assertSame('bar', $headers->get('Fake')->getFieldValue());
    }

    #[Test]
    public function headersAggregatesHeaderThroughAddHeader(): void
    {
        $headers = new Mail\Headers();
        $headers->addHeader(new Header\GenericHeader('Fake', 'bar'));
        static::assertSame(1, $headers->count());
        static::assertInstanceOf(GenericHeader::class, $headers->get('Fake'));
    }

    #[Test]
    public function headersAggregatesHeaderThroughAddHeaderLine(): void
    {
        $headers = new Mail\Headers();
        $headers->addHeaderLine('Fake', 'bar');
        static::assertSame(1, $headers->count());
        static::assertInstanceOf(GenericHeader::class, $headers->get('Fake'));
    }

    #[Test]
    public function headersAddHeaderLineThrowsExceptionOnMissingFieldValue(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Header must match with the format "name:value"');
        $headers = new Mail\Headers();
        $headers->addHeaderLine('Foo');
    }

    #[Test]
    public function headersAddHeaderLineThrowsExceptionOnInvalidFieldNull(): void
    {
        $headers = new Mail\Headers();

        $this->expectException(Mail\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('expects its first argument to be a string');
        $headers->addHeaderLine(null);
    }

    #[Test]
    public function headersAddHeaderLineThrowsExceptionOnInvalidFieldObject(): void
    {
        $headers = new Mail\Headers();
        $object  = new stdClass();

        $this->expectException(Mail\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('expects its first argument to be a string');
        $headers->addHeaderLine($object);
    }

    #[Test]
    public function headersAggregatesHeadersThroughAddHeaders(): void
    {
        $headers = new Mail\Headers();
        $headers->addHeaders([new Header\GenericHeader('Foo', 'bar'), new Header\GenericHeader('Baz', 'baz')]);
        static::assertSame(2, $headers->count());
        static::assertInstanceOf(GenericHeader::class, $headers->get('Foo'));
        static::assertSame('bar', $headers->get('foo')->getFieldValue());
        static::assertSame('baz', $headers->get('baz')->getFieldValue());

        $headers = new Mail\Headers();
        $headers->addHeaders(['Foo: bar', 'Baz: baz']);
        static::assertSame(2, $headers->count());
        static::assertInstanceOf(GenericHeader::class, $headers->get('Foo'));
        static::assertSame('bar', $headers->get('foo')->getFieldValue());
        static::assertSame('baz', $headers->get('baz')->getFieldValue());

        $headers = new Mail\Headers();
        $headers->addHeaders([['Foo' => 'bar'], ['Baz' => 'baz']]);
        static::assertSame(2, $headers->count());
        static::assertInstanceOf(GenericHeader::class, $headers->get('Foo'));
        static::assertSame('bar', $headers->get('foo')->getFieldValue());
        static::assertSame('baz', $headers->get('baz')->getFieldValue());

        $headers = new Mail\Headers();
        $headers->addHeaders([['Foo', 'bar'], ['Baz', 'baz']]);
        static::assertSame(2, $headers->count());
        static::assertInstanceOf(GenericHeader::class, $headers->get('Foo'));
        static::assertSame('bar', $headers->get('foo')->getFieldValue());
        static::assertSame('baz', $headers->get('baz')->getFieldValue());

        $headers = new Mail\Headers();
        $headers->addHeaders(['Foo' => 'bar', 'Baz' => 'baz']);
        static::assertSame(2, $headers->count());
        static::assertInstanceOf(GenericHeader::class, $headers->get('Foo'));
        static::assertSame('bar', $headers->get('foo')->getFieldValue());
        static::assertSame('baz', $headers->get('baz')->getFieldValue());
    }

    #[Test]
    public function headersAddHeadersThrowsExceptionOnInvalidArguments(): void
    {
        $this->expectException(Mail\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected array or Traversable');
        $headers = new Mail\Headers();
        $headers->addHeaders('foo');
    }

    #[Test]
    public function headersCanRemoveHeader(): void
    {
        $headers = new Mail\Headers();
        $headers->addHeaders(['Foo' => 'bar', 'Baz' => 'baz']);
        static::assertSame(2, $headers->count());
        $headers->removeHeader('foo');
        static::assertSame(1, $headers->count());
        static::assertFalse($headers->has('foo'));
        static::assertTrue($headers->has('baz'));
    }

    #[Test]
    public function removeHeaderWithFieldNameWillRemoveAllInstances(): void
    {
        $headers = new Mail\Headers();
        $headers->addHeaders([['Foo' => 'foo'], ['Foo' => 'bar'], 'Baz' => 'baz']);
        static::assertSame(3, $headers->count());
        $headers->removeHeader('foo');
        static::assertSame(1, $headers->count());
        static::assertFalse($headers->get('foo'));
        static::assertTrue($headers->has('baz'));
    }

    #[Test]
    public function removeHeaderWithInstanceWillRemoveThatInstance(): void
    {
        $headers = new Mail\Headers();
        $headers->addHeaders([['Foo' => 'foo'], ['Foo' => 'bar'], 'Baz' => 'baz']);
        $header = $headers->get('foo')->current();
        static::assertSame(3, $headers->count());
        $headers->removeHeader($header);
        static::assertSame(2, $headers->count());
        static::assertTrue($headers->has('foo'));
        static::assertNotSame($header, $headers->get('foo'));
    }

    #[Test]
    public function removeHeaderWhenEmpty(): void
    {
        $headers = new Mail\Headers();
        static::assertFalse($headers->removeHeader(''));
    }

    #[Test]
    public function headersCanClearAllHeaders(): void
    {
        $headers = new Mail\Headers();
        $headers->addHeaders(['Foo' => 'bar', 'Baz' => 'baz']);
        static::assertSame(2, $headers->count());
        $headers->clearHeaders();
        static::assertSame(0, $headers->count());
    }

    #[Test]
    public function headersCanBeIterated(): void
    {
        $headers = new Mail\Headers();
        $headers->addHeaders(['Foo' => 'bar', 'Baz' => 'baz']);
        $iterations = 0;
        foreach ($headers as $index => $header) {
            $iterations++;
            static::assertInstanceOf(GenericHeader::class, $header);
            switch ($index) {
                case 0:
                    static::assertSame('bar', $header->getFieldValue());
                    break;
                case 1:
                    static::assertSame('baz', $header->getFieldValue());
                    break;
                default:
                    static::fail('Invalid index returned from iterator');
            }
        }
        static::assertSame(2, $iterations);
    }

    #[Test]
    public function headersCanBeCastToString(): void
    {
        $headers = new Mail\Headers();
        $headers->addHeaders(['Foo' => 'bar', 'Baz' => 'baz']);
        static::assertSame('Foo: bar' . "\r\n" . 'Baz: baz' . "\r\n", $headers->toString());
    }

    #[Test]
    public function headersCanBeCastToArray(): void
    {
        $headers = new Mail\Headers();
        $headers->addHeaders(['Foo' => 'bar', 'Baz' => 'baz']);
        static::assertSame(['Foo' => 'bar', 'Baz' => 'baz'], $headers->toArray());
    }

    #[Test]
    public function castingToArrayReturnsMultiHeadersAsArrays(): void
    {
        $headers = new Mail\Headers();

        // @codingStandardsIgnoreStart
        $received1 = Header\Received::fromString(
            "Received: from framework (localhost [127.0.0.1])\r\n by framework (Postfix) with ESMTP id BBBBBBBBBBB\r\n for <laminas@framework>; Mon, 21 Nov 2011 12:50:27 -0600 (CST)",
        );
        $received2 = Header\Received::fromString(
            "Received: from framework (localhost [127.0.0.1])\r\n by framework (Postfix) with ESMTP id AAAAAAAAAAA\r\n for <laminas@framework>; Mon, 21 Nov 2011 12:50:29 -0600 (CST)",
        );
        // @codingStandardsIgnoreEnd

        $headers->addHeader($received1);
        $headers->addHeader($received2);
        $array    = $headers->toArray();
        $expected = [
            'Received' => [
                $received1->getFieldValue(),
                $received2->getFieldValue(),
            ],
        ];
        static::assertSame($expected, $array);
    }

    #[Test]
    public function castingToStringReturnsAllMultiHeaderValues(): void
    {
        $headers = new Mail\Headers();

        // @codingStandardsIgnoreStart
        $received1 = Header\Received::fromString(
            "Received: from framework (localhost [127.0.0.1])\r\n by framework (Postfix) with ESMTP id BBBBBBBBBBB\r\n for <laminas@framework>; Mon, 21 Nov 2011 12:50:27 -0600 (CST)",
        );
        $received2 = Header\Received::fromString(
            "Received: from framework (localhost [127.0.0.1])\r\n by framework (Postfix) with ESMTP id AAAAAAAAAAA\r\n for <laminas@framework>; Mon, 21 Nov 2011 12:50:29 -0600 (CST)",
        );
        // @codingStandardsIgnoreEnd

        $headers->addHeader($received1);
        $headers->addHeader($received2);
        $string   = $headers->toString();
        $expected = [
            'Received: ' . $received1->getFieldValue(),
            'Received: ' . $received2->getFieldValue(),
        ];
        $expected = implode("\r\n", $expected) . "\r\n";
        static::assertSame($expected, $string);
    }

    #[Test]
    public function getReturnsArrayIterator(): void
    {
        $headers  = new Mail\Headers();
        $received = Header\Received::fromString('Received: from framework (localhost [127.0.0.1])');
        $headers->addHeader($received);

        $return = $headers->get('Received');
        static::assertSame(ArrayIterator::class, $return::class);
    }

    /**
     * Test that toArray can take format parameter
     *
     * @see https://github.com/zendframework/zend-mail/pull/61
     */
    #[Test]
    public function toArrayFormatRaw(): void
    {
        $rawSubject = '=?ISO-8859-2?Q?PD=3A_My=3A_Go=B3?= =?ISO-8859-2?Q?blahblah?=';
        $headers    = new Mail\Headers();
        $subject    = Header\Subject::fromString("Subject: $rawSubject");
        $headers->addHeader($subject);
        // default
        $array    = $headers->toArray(Header\HeaderInterface::FORMAT_RAW);
        $expected = [
            'Subject' => 'PD: My: Gołblahblah',
        ];
        static::assertSame($expected, $array);
    }

    /**
     * Test that toArray can take format parameter
     *
     * @see https://github.com/zendframework/zend-mail/pull/61
     */
    #[Test]
    public function toArrayFormatEncoded(): void
    {
        $rawSubject = '=?ISO-8859-2?Q?PD=3A_My=3A_Go=B3?= =?ISO-8859-2?Q?blahblah?=';
        $headers    = new Mail\Headers();
        $subject    = Header\Subject::fromString("Subject: $rawSubject");
        $headers->addHeader($subject);

        // encoded
        $array    = $headers->toArray(Header\HeaderInterface::FORMAT_ENCODED);
        $expected = [
            'Subject' => '=?UTF-8?Q?PD:=20My:=20Go=C5=82blahblah?=',
        ];
        static::assertSame($expected, $array);
    }

    #[Test]
    public function clone(): void
    {
        $headers = new Mail\Headers();
        $headers->addHeader(new Header\Bcc());
        $headers2 = clone $headers;
        static::assertEquals($headers, $headers2);
        $headers2->removeHeader('Bcc');
        static::assertTrue($headers->has('Bcc'));
        static::assertFalse($headers2->has('Bcc'));
    }

    #[Test]
    #[Group('ZF2015-04')]
    public function headerCrLfAttackFromString(): void
    {
        $this->expectException(Mail\Exception\RuntimeException::class);
        Mail\Headers::fromString("Fake: foo-bar\r\n\r\nevilContent");
    }

    #[Test]
    #[Group('ZF2015-04')]
    public function headerCrLfAttackAddHeaderLineSingle(): void
    {
        $headers = new Mail\Headers();
        $this->expectException(Exception\InvalidArgumentException::class);
        $headers->addHeaderLine("Fake: foo-bar\r\n\r\nevilContent");
    }

    #[Test]
    #[Group('ZF2015-04')]
    public function headerCrLfAttackAddHeaderLineWithValue(): void
    {
        $headers = new Mail\Headers();
        $this->expectException(Exception\InvalidArgumentException::class);
        $headers->addHeaderLine('Fake', "foo-bar\r\n\r\nevilContent");
    }

    #[Test]
    #[Group('ZF2015-04')]
    public function headerCrLfAttackAddHeaderLineMultiple(): void
    {
        $headers = new Mail\Headers();
        $this->expectException(Exception\InvalidArgumentException::class);
        $headers->addHeaderLine('Fake', ["foo-bar\r\n\r\nevilContent"]);
        $headers->forceLoading();
    }

    #[Test]
    #[Group('ZF2015-04')]
    public function headerCrLfAttackAddHeadersSingle(): void
    {
        $headers = new Mail\Headers();
        $this->expectException(Exception\InvalidArgumentException::class);
        $headers->addHeaders(["Fake: foo-bar\r\n\r\nevilContent"]);
    }

    #[Test]
    #[Group('ZF2015-04')]
    public function headerCrLfAttackAddHeadersWithValue(): void
    {
        $headers = new Mail\Headers();
        $this->expectException(Exception\InvalidArgumentException::class);
        $headers->addHeaders(['Fake' => "foo-bar\r\n\r\nevilContent"]);
    }

    #[Test]
    #[Group('ZF2015-04')]
    public function headerCrLfAttackAddHeadersMultiple(): void
    {
        $headers = new Mail\Headers();
        $this->expectException(Exception\InvalidArgumentException::class);
        $headers->addHeaders(['Fake' => ["foo-bar\r\n\r\nevilContent"]]);
        $headers->forceLoading();
    }

    #[Test]
    public function addressListGetEncodedFieldValueWithUtf8Domain(): void
    {
        $to = new Header\To();
        $to->setEncoding('UTF-8');
        $to->getAddressList()->add('local-part@ä-umlaut.de');
        $encodedValue = $to->getFieldValue(Header\HeaderInterface::FORMAT_ENCODED);
        static::assertSame('local-part@xn---umlaut-4wa.de', $encodedValue);
    }

    /**
     * Test ">" being part of email "comment".
     *
     * Example Email-header:
     *  "Foo <bar" foo.bar@test.com
     *
     * Description:
     *   The example email-header should be valid
     *   according to https://tools.ietf.org/html/rfc2822#section-3.4
     *   but the function AdressList.php/addFromString matches it incorrect.
     *   The result has the following form:
     *    "bar <foo.bar@test.com"
     *   This is clearly not a valid adress and therefore causes
     *   exceptions in the following code
     *
     * @see https://github.com/zendframework/zend-mail/issues/127
     */
    #[Test]
    public function emailNameParser(): void
    {
        $to = Header\To::fromString('To: "=?UTF-8?Q?=C3=B5lu?= <bar" <foo.bar@test.com>');

        $address = $to->getAddressList()->get('foo.bar@test.com');
        static::assertSame('õlu <bar', $address->getName());
        static::assertSame('foo.bar@test.com', $address->getEmail());

        $encodedValue = $to->getFieldValue(Header\HeaderInterface::FORMAT_ENCODED);
        static::assertSame('=?UTF-8?Q?"=C3=B5lu=20<bar"?= <foo.bar@test.com>', $encodedValue);

        $encodedValue = $to->getFieldValue(Header\HeaderInterface::FORMAT_RAW);
        static::assertSame('"õlu <bar" <foo.bar@test.com>', $encodedValue);
    }

    #[Test]
    public function defaultEncoding(): void
    {
        $headers = new Mail\Headers();
        static::assertSame('ASCII', $headers->getEncoding());
    }

    #[Test]
    public function setEncodingNoHeaders(): void
    {
        $headers = new Mail\Headers();
        $headers->setEncoding('UTF-8');
        static::assertSame('UTF-8', $headers->getEncoding());
    }

    #[Test]
    public function setEncodingWithHeaders(): void
    {
        $headers = new Mail\Headers();
        $headers->addHeaderLine('To: test@example.com');
        $headers->addHeaderLine('Cc: tester@example.org');

        $headers->setEncoding('UTF-8');
        static::assertSame('UTF-8', $headers->getEncoding());
    }

    #[Test]
    public function addHeaderCallsSetEncoding(): void
    {
        $headers = new Mail\Headers();
        $headers->setEncoding('UTF-8');

        $subject = new Header\Subject();
        // default to ASCII
        static::assertSame('ASCII', $subject->getEncoding());

        $headers->addHeader($subject);
        // now UTF-8 via addHeader() call
        static::assertSame('UTF-8', $subject->getEncoding());
    }

    #[Test]
    public function getHeaderLocatorReturnsHeaderLocatorInstanceByDefault(): void
    {
        $headers = new Mail\Headers();
        $locator = $headers->getHeaderLocator();
        static::assertInstanceOf(Mail\Header\HeaderLocator::class, $locator);
    }

    #[Test]
    public function canInjectAlternateHeaderLocatorInstance(): void
    {
        $headers = new Mail\Headers();
        $locator = $this->createMock(Mail\Header\HeaderLocatorInterface::class);

        $headers->setHeaderLocator($locator);
        static::assertSame($locator, $headers->getHeaderLocator());
    }

    #[Test]
    public function strictKeyComparisonInHas(): void
    {
        $headers = Mail\Headers::fromString('000: foo-bar');
        static::assertFalse($headers->has('0'));
    }

    #[Test]
    public function strictKeyComparisonInGet(): void
    {
        $headers = Mail\Headers::fromString('000: foo-bar');
        static::assertFalse($headers->get('0'));
    }

    #[Test]
    #[Group('issue-175')]
    public function undefinedDefineMissingIntlExtensionConstants(): void
    {
        $headers = Mail\Headers::fromString('To: foo@example.com')->setEncoding('UTF-8');

        static::assertSame(['To' => 'foo@example.com'], $headers->toArray());

        static::assertSame('To: foo@example.com' . Mail\Headers::EOL, $headers->toString());
    }
}
