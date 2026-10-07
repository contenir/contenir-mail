<?php

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Exception;
use Contenir\Mail\Header;
use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Message as MimeMessage;
use Contenir\Mail\Mime\Mime;
use Contenir\Mail\Mime\Part as MimePart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

use function count;
use function date;
use function file_get_contents;
use function implode;
use function substr;

#[CoversClass(\Contenir\Mail\Message::class)]
class MessageTest extends TestCase
{
    /** @var Message */
    public $message;

    public function setUp(): void
    {
        $this->message = new Message();
    }

    #[Test]
    public function invalidByDefault(): void
    {
        static::assertFalse($this->message->isValid());
    }

    #[Test]
    public function setsOrigDateHeaderByDefault(): void
    {
        $headers = $this->message->getHeaders();
        static::assertInstanceOf(Headers::class, $headers);
        static::assertTrue($headers->has('date'));
        $header = $headers->get('date');
        $date   = date('r');
        $date   = substr($date, 0, 16);
        $test   = $header->getFieldValue();
        $test   = substr($test, 0, 16);
        static::assertSame($date, $test);
    }

    #[Test]
    public function addingFromAddressMarksAsValid(): void
    {
        $this->message->addFrom('test@example.com');
        static::assertTrue($this->message->isValid());
    }

    #[Test]
    public function headersMethodReturnsHeadersObject(): void
    {
        $headers = $this->message->getHeaders();
        static::assertInstanceOf(Headers::class, $headers);
    }

    #[Test]
    public function toMethodReturnsAddressListObject(): void
    {
        $this->message->addTo('test@example.com');
        $to = $this->message->getTo();
        static::assertInstanceOf(AddressList::class, $to);
    }

    #[Test]
    public function toAddressListLivesInHeaders(): void
    {
        $this->message->addTo('test@example.com');
        $to      = $this->message->getTo();
        $headers = $this->message->getHeaders();
        static::assertInstanceOf(Headers::class, $headers);
        static::assertTrue($headers->has('to'));
        $header = $headers->get('to');
        static::assertSame($header->getAddressList(), $to);
    }

    #[Test]
    public function fromMethodReturnsAddressListObject(): void
    {
        $this->message->addFrom('test@example.com');
        $from = $this->message->getFrom();
        static::assertInstanceOf(AddressList::class, $from);
    }

    #[Test]
    public function fromAddressListLivesInHeaders(): void
    {
        $this->message->addFrom('test@example.com');
        $from    = $this->message->getFrom();
        $headers = $this->message->getHeaders();
        static::assertInstanceOf(Headers::class, $headers);
        static::assertTrue($headers->has('from'));
        $header = $headers->get('from');
        static::assertSame($header->getAddressList(), $from);
    }

    #[Test]
    public function ccMethodReturnsAddressListObject(): void
    {
        $this->message->addCc('test@example.com');
        $cc = $this->message->getCc();
        static::assertInstanceOf(AddressList::class, $cc);
    }

    #[Test]
    public function ccAddressListLivesInHeaders(): void
    {
        $this->message->addCc('test@example.com');
        $cc      = $this->message->getCc();
        $headers = $this->message->getHeaders();
        static::assertInstanceOf(Headers::class, $headers);
        static::assertTrue($headers->has('cc'));
        $header = $headers->get('cc');
        static::assertSame($header->getAddressList(), $cc);
    }

    #[Test]
    public function bccMethodReturnsAddressListObject(): void
    {
        $this->message->addBcc('test@example.com');
        $bcc = $this->message->getBcc();
        static::assertInstanceOf(AddressList::class, $bcc);
    }

    #[Test]
    public function bccAddressListLivesInHeaders(): void
    {
        $this->message->addBcc('test@example.com');
        $bcc     = $this->message->getBcc();
        $headers = $this->message->getHeaders();
        static::assertInstanceOf(Headers::class, $headers);
        static::assertTrue($headers->has('bcc'));
        $header = $headers->get('bcc');
        static::assertSame($header->getAddressList(), $bcc);
    }

    #[Test]
    public function replyToMethodReturnsAddressListObject(): void
    {
        $this->message->addReplyTo('test@example.com');
        $replyTo = $this->message->getReplyTo();
        static::assertInstanceOf(AddressList::class, $replyTo);
    }

    #[Test]
    public function replyToAddressListLivesInHeaders(): void
    {
        $this->message->addReplyTo('test@example.com');
        $replyTo = $this->message->getReplyTo();
        $headers = $this->message->getHeaders();
        static::assertInstanceOf(Headers::class, $headers);
        static::assertTrue($headers->has('reply-to'));
        $header = $headers->get('reply-to');
        static::assertSame($header->getAddressList(), $replyTo);
    }

    #[Test]
    public function senderIsNullByDefault(): void
    {
        static::assertNull($this->message->getSender());
    }

    #[Test]
    public function nullSenderDoesNotCreateHeader(): void
    {
        $sender  = $this->message->getSender();
        $headers = $this->message->getHeaders();
        static::assertFalse($headers->has('sender'));
    }

    #[Test]
    public function settingSenderCreatesAddressObject(): void
    {
        $this->message->setSender('test@example.com');
        $sender = $this->message->getSender();
        static::assertInstanceOf(Address::class, $sender);
    }

    #[Test]
    public function canSpecifyNameWhenSettingSender(): void
    {
        $this->message->setSender('test@example.com', 'Example Test');
        $sender = $this->message->getSender();
        static::assertInstanceOf(Address::class, $sender);
        static::assertSame('Example Test', $sender->getName());
    }

    #[Test]
    public function canProvideAddressObjectWhenSettingSender(): void
    {
        $sender = new Address('test@example.com');
        $this->message->setSender($sender);
        $test = $this->message->getSender();
        static::assertSame($sender, $test);
    }

    #[Test]
    public function senderAccessorsProxyToSenderHeader(): void
    {
        $header = new Header\Sender();
        $this->message->getHeaders()->addHeader($header);
        $address = new Address('test@example.com', 'Example Test');
        $this->message->setSender($address);
        static::assertSame($address, $header->getAddress());
    }

    #[Test]
    public function canAddFromAddressUsingName(): void
    {
        $this->message->addFrom('test@example.com', 'Example Test');
        $addresses = $this->message->getFrom();
        static::assertSame(1, count($addresses));
        $address = $addresses->current();
        static::assertSame('test@example.com', $address->getEmail());
        static::assertSame('Example Test', $address->getName());
    }

    #[Test]
    public function canAddFromAddressUsingEmailAndNameAsString(): void
    {
        $this->message->addFrom('Example Test <test@example.com>');
        $addresses = $this->message->getFrom();
        static::assertSame(1, count($addresses));
        $address = $addresses->current();
        static::assertSame('test@example.com', $address->getEmail());
        static::assertSame('Example Test', $address->getName());
    }

    #[Test]
    public function canAddFromAddressUsingAddressObject(): void
    {
        $address = new Address('test@example.com', 'Example Test');
        $this->message->addFrom($address);

        $addresses = $this->message->getFrom();
        static::assertSame(1, count($addresses));
        $test = $addresses->current();
        static::assertSame($address, $test);
    }

    #[Test]
    public function canAddManyFromAddressesUsingArray(): void
    {
        $addresses = [
            'test@example.com',
            'list@example.com' => 'Laminas Contributors List',
            new Address('announce@example.com', 'Laminas Announce List'),
        ];
        $this->message->addFrom($addresses);

        $from = $this->message->getFrom();
        static::assertSame(3, count($from));

        static::assertTrue($from->has('test@example.com'));
        static::assertTrue($from->has('list@example.com'));
        static::assertTrue($from->has('announce@example.com'));
    }

    #[Test]
    public function canAddManyFromAddressesUsingAddressListObject(): void
    {
        $list = new AddressList();
        $list->add('test@example.com');

        $this->message->addFrom('announce@example.com');
        $this->message->addFrom($list);
        $from = $this->message->getFrom();
        static::assertSame(2, count($from));
        static::assertTrue($from->has('announce@example.com'));
        static::assertTrue($from->has('test@example.com'));
    }

    #[Test]
    public function canSetFromListFromAddressList(): void
    {
        $list = new AddressList();
        $list->add('test@example.com');

        $this->message->addFrom('announce@example.com');
        $this->message->setFrom($list);
        $from = $this->message->getFrom();
        static::assertSame(1, count($from));
        static::assertFalse($from->has('announce@example.com'));
        static::assertTrue($from->has('test@example.com'));
    }

    #[Test]
    public function canAddCcAddressUsingName(): void
    {
        $this->message->addCc('test@example.com', 'Example Test');
        $addresses = $this->message->getCc();
        static::assertSame(1, count($addresses));
        $address = $addresses->current();
        static::assertSame('test@example.com', $address->getEmail());
        static::assertSame('Example Test', $address->getName());
    }

    #[Test]
    public function canAddCcAddressUsingAddressObject(): void
    {
        $address = new Address('test@example.com', 'Example Test');
        $this->message->addCc($address);

        $addresses = $this->message->getCc();
        static::assertSame(1, count($addresses));
        $test = $addresses->current();
        static::assertSame($address, $test);
    }

    #[Test]
    public function canAddManyCcAddressesUsingArray(): void
    {
        $addresses = [
            'test@example.com',
            'list@example.com' => 'Laminas Contributors List',
            new Address('announce@example.com', 'Laminas Announce List'),
        ];
        $this->message->addCc($addresses);

        $cc = $this->message->getCc();
        static::assertSame(3, count($cc));

        static::assertTrue($cc->has('test@example.com'));
        static::assertTrue($cc->has('list@example.com'));
        static::assertTrue($cc->has('announce@example.com'));
    }

    #[Test]
    public function canAddManyCcAddressesUsingAddressListObject(): void
    {
        $list = new AddressList();
        $list->add('test@example.com');

        $this->message->addCc('announce@example.com');
        $this->message->addCc($list);
        $cc = $this->message->getCc();
        static::assertSame(2, count($cc));
        static::assertTrue($cc->has('announce@example.com'));
        static::assertTrue($cc->has('test@example.com'));
    }

    #[Test]
    public function canSetCcListFromAddressList(): void
    {
        $list = new AddressList();
        $list->add('test@example.com');

        $this->message->addCc('announce@example.com');
        $this->message->setCc($list);
        $cc = $this->message->getCc();
        static::assertSame(1, count($cc));
        static::assertFalse($cc->has('announce@example.com'));
        static::assertTrue($cc->has('test@example.com'));
    }

    #[Test]
    public function canAddBccAddressUsingName(): void
    {
        $this->message->addBcc('test@example.com', 'Example Test');
        $addresses = $this->message->getBcc();
        static::assertSame(1, count($addresses));
        $address = $addresses->current();
        static::assertSame('test@example.com', $address->getEmail());
        static::assertSame('Example Test', $address->getName());
    }

    #[Test]
    public function canAddBccAddressUsingAddressObject(): void
    {
        $address = new Address('test@example.com', 'Example Test');
        $this->message->addBcc($address);

        $addresses = $this->message->getBcc();
        static::assertSame(1, count($addresses));
        $test = $addresses->current();
        static::assertSame($address, $test);
    }

    #[Test]
    public function canAddManyBccAddressesUsingArray(): void
    {
        $addresses = [
            'test@example.com',
            'list@example.com' => 'Laminas Contributors List',
            new Address('announce@example.com', 'Laminas Announce List'),
        ];
        $this->message->addBcc($addresses);

        $bcc = $this->message->getBcc();
        static::assertSame(3, count($bcc));

        static::assertTrue($bcc->has('test@example.com'));
        static::assertTrue($bcc->has('list@example.com'));
        static::assertTrue($bcc->has('announce@example.com'));
    }

    #[Test]
    public function canAddManyBccAddressesUsingAddressListObject(): void
    {
        $list = new AddressList();
        $list->add('test@example.com');

        $this->message->addBcc('announce@example.com');
        $this->message->addBcc($list);
        $bcc = $this->message->getBcc();
        static::assertSame(2, count($bcc));
        static::assertTrue($bcc->has('announce@example.com'));
        static::assertTrue($bcc->has('test@example.com'));
    }

    #[Test]
    public function canSetBccListFromAddressList(): void
    {
        $list = new AddressList();
        $list->add('test@example.com');

        $this->message->addBcc('announce@example.com');
        $this->message->setBcc($list);
        $bcc = $this->message->getBcc();
        static::assertSame(1, count($bcc));
        static::assertFalse($bcc->has('announce@example.com'));
        static::assertTrue($bcc->has('test@example.com'));
    }

    #[Test]
    public function canAddReplyToAddressUsingName(): void
    {
        $this->message->addReplyTo('test@example.com', 'Example Test');
        $addresses = $this->message->getReplyTo();
        static::assertSame(1, count($addresses));
        $address = $addresses->current();
        static::assertSame('test@example.com', $address->getEmail());
        static::assertSame('Example Test', $address->getName());
    }

    #[Test]
    public function canAddReplyToAddressUsingAddressObject(): void
    {
        $address = new Address('test@example.com', 'Example Test');
        $this->message->addReplyTo($address);

        $addresses = $this->message->getReplyTo();
        static::assertSame(1, count($addresses));
        $test = $addresses->current();
        static::assertSame($address, $test);
    }

    #[Test]
    public function canAddManyReplyToAddressesUsingArray(): void
    {
        $addresses = [
            'test@example.com',
            'list@example.com' => 'Laminas Contributors List',
            new Address('announce@example.com', 'Laminas Announce List'),
        ];
        $this->message->addReplyTo($addresses);

        $replyTo = $this->message->getReplyTo();
        static::assertSame(3, count($replyTo));

        static::assertTrue($replyTo->has('test@example.com'));
        static::assertTrue($replyTo->has('list@example.com'));
        static::assertTrue($replyTo->has('announce@example.com'));
    }

    #[Test]
    public function canAddManyReplyToAddressesUsingAddressListObject(): void
    {
        $list = new AddressList();
        $list->add('test@example.com');

        $this->message->addReplyTo('announce@example.com');
        $this->message->addReplyTo($list);
        $replyTo = $this->message->getReplyTo();
        static::assertSame(2, count($replyTo));
        static::assertTrue($replyTo->has('announce@example.com'));
        static::assertTrue($replyTo->has('test@example.com'));
    }

    #[Test]
    public function canSetReplyToListFromAddressList(): void
    {
        $list = new AddressList();
        $list->add('test@example.com');

        $this->message->addReplyTo('announce@example.com');
        $this->message->setReplyTo($list);
        $replyTo = $this->message->getReplyTo();
        static::assertSame(1, count($replyTo));
        static::assertFalse($replyTo->has('announce@example.com'));
        static::assertTrue($replyTo->has('test@example.com'));
    }

    #[Test]
    public function subjectIsEmptyByDefault(): void
    {
        static::assertNull($this->message->getSubject());
    }

    #[Test]
    public function subjectIsMutable(): void
    {
        $this->message->setSubject('test subject');
        $subject = $this->message->getSubject();
        static::assertSame('test subject', $subject);
    }

    #[Test]
    public function subjectIsMutableReplaceExisting(): void
    {
        $this->message->setSubject('test subject');
        $this->message->setSubject('new subject');
        static::assertSame('new subject', $this->message->getSubject());
    }

    #[Test]
    public function settingSubjectProxiesToHeader(): void
    {
        $this->message->setSubject('test subject');
        $headers = $this->message->getHeaders();
        static::assertInstanceOf(Headers::class, $headers);
        static::assertTrue($headers->has('subject'));
        $header = $headers->get('subject');
        static::assertSame('test subject', $header->getFieldValue());
    }

    #[Test]
    public function bodyIsEmptyByDefault(): void
    {
        static::assertNull($this->message->getBody());
    }

    #[Test]
    public function maySetBodyFromString(): void
    {
        $this->message->setBody('body');
        static::assertSame('body', $this->message->getBody());
    }

    #[Test]
    public function maySetBodyFromStringSerializableObject(): void
    {
        $object = new TestAsset\StringSerializableObject('body');
        $this->message->setBody($object);
        static::assertSame($object, $this->message->getBody());
        static::assertSame('body', $this->message->getBodyText());
    }

    #[Test]
    public function maySetBodyFromMimeMessage(): void
    {
        $body = new MimeMessage();
        $this->message->setBody($body);
        static::assertSame($body, $this->message->getBody());
    }

    #[Test]
    public function maySetNullBody(): void
    {
        $this->message->setBody(null);
        static::assertNull($this->message->getBody());
    }

    public static function invalidBodyValues(): array
    {
        return [
            [['foo']],
            [true],
            [false],
            [new stdClass()],
        ];
    }

    /**
     */
    #[Test]
    #[DataProvider('invalidBodyValues')]
    public function settingNonScalarNonMimeNonStringSerializableValueForBodyRaisesException(mixed $body): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->message->setBody($body);
    }

    #[Test]
    public function settingBodyFromSinglePartMimeMessageSetsAppropriateHeaders(): void
    {
        $mime       = new Mime('foo-bar');
        $part       = new MimePart('<b>foo</b>');
        $part->type = 'text/html';
        $body       = new MimeMessage();
        $body->setMime($mime);
        $body->addPart($part);

        $this->message->setBody($body);
        $headers = $this->message->getHeaders();
        static::assertInstanceOf(Headers::class, $headers);

        static::assertTrue($headers->has('mime-version'));
        $header = $headers->get('mime-version');
        static::assertSame('1.0', $header->getFieldValue());

        static::assertTrue($headers->has('content-type'));
        $header = $headers->get('content-type');
        static::assertSame('text/html', $header->getFieldValue());
    }

    #[Test]
    public function settingUtf8MailBodyFromSinglePartMimeUtf8MessageSetsAppropriateHeaders(): void
    {
        $mime           = new Mime('foo-bar');
        $part           = new MimePart('UTF-8 TestString: AaÜüÄäÖöß');
        $part->type     = Mime::TYPE_TEXT;
        $part->encoding = Mime::ENCODING_QUOTEDPRINTABLE;
        $part->charset  = 'utf-8';
        $body           = new MimeMessage();
        $body->setMime($mime);
        $body->addPart($part);

        $this->message->setEncoding('UTF-8');
        $this->message->setBody($body);

        static::assertStringContainsString(
            'Content-Type: text/plain;'
                . Headers::FOLDING
                . 'charset="utf-8"'
                . Headers::EOL
                . 'Content-Transfer-Encoding: quoted-printable'
                . Headers::EOL,
            $this->message->getHeaders()->toString(),
        );
    }

    #[Test]
    public function settingBodyFromMultiPartMimeMessageSetsAppropriateHeaders(): void
    {
        $mime       = new Mime('foo-bar');
        $text       = new MimePart('foo');
        $text->type = 'text/plain';
        $html       = new MimePart('<b>foo</b>');
        $html->type = 'text/html';
        $body       = new MimeMessage();
        $body->setMime($mime);
        $body->addPart($text);
        $body->addPart($html);

        $this->message->setBody($body);
        $headers = $this->message->getHeaders();
        static::assertInstanceOf(Headers::class, $headers);

        static::assertTrue($headers->has('mime-version'));
        $header = $headers->get('mime-version');
        static::assertSame('1.0', $header->getFieldValue());

        static::assertTrue($headers->has('content-type'));
        $header = $headers->get('content-type');
        static::assertSame("multipart/mixed;\r\n boundary=\"foo-bar\"", $header->getFieldValue());
    }

    #[Test]
    public function retrievingBodyTextFromMessageWithMultiPartMimeBodyReturnsMimeSerialization(): void
    {
        $mime       = new Mime('foo-bar');
        $text       = new MimePart('foo');
        $text->type = 'text/plain';
        $html       = new MimePart('<b>foo</b>');
        $html->type = 'text/html';
        $body       = new MimeMessage();
        $body->setMime($mime);
        $body->addPart($text);
        $body->addPart($html);

        $this->message->setBody($body);

        $text = $this->message->getBodyText();
        static::assertSame($body->generateMessage(Headers::EOL), $text);
        static::assertStringContainsString('--foo-bar', $text);
        static::assertStringContainsString('--foo-bar--', $text);
        static::assertStringContainsString('Content-Type: text/plain', $text);
        static::assertStringContainsString('Content-Type: text/html', $text);
    }

    #[Test]
    public function encodingIsAsciiByDefault(): void
    {
        static::assertSame('ASCII', $this->message->getEncoding());
    }

    #[Test]
    public function encodingIsMutable(): void
    {
        $this->message->setEncoding('UTF-8');
        static::assertSame('UTF-8', $this->message->getEncoding());
    }

    #[Test]
    public function messageReturnsNonEncodedSubject(): void
    {
        $this->message->setSubject('This is a subject');
        $this->message->setEncoding('UTF-8');
        static::assertSame('This is a subject', $this->message->getSubject());
    }

    #[Test]
    public function settingNonAsciiEncodingForcesMimeEncodingOfSomeHeaders(): void
    {
        $this->message->addTo('test@example.com', 'Laminas DevTeam');
        $this->message->addFrom('matthew@example.com', "Matthew Weier O'Phinney");
        $this->message->addCc('list@example.com', 'Laminas Contributors List');
        $this->message->addBcc('devs@example.com', 'Laminas CR Team');
        $this->message->setSubject('This is a subject');
        $this->message->setEncoding('UTF-8');

        $test = $this->message->getHeaders()->toString();

        $expected = '=?UTF-8?Q?Laminas=20DevTeam?=';
        static::assertStringContainsString($expected, $test);
        static::assertStringContainsString('<test@example.com>', $test);

        $expected = "=?UTF-8?Q?Matthew=20Weier=20O'Phinney?=";
        static::assertStringContainsString($expected, $test, $test);
        static::assertStringContainsString('<matthew@example.com>', $test);

        $expected = '=?UTF-8?Q?Laminas=20Contributors=20List?=';
        static::assertStringContainsString($expected, $test);
        static::assertStringContainsString('<list@example.com>', $test);

        $expected = '=?UTF-8?Q?Laminas=20CR=20Team?=';
        static::assertStringContainsString($expected, $test);
        static::assertStringContainsString('<devs@example.com>', $test);

        $expected = 'Subject: =?UTF-8?Q?This=20is=20a=20subject?=';
        static::assertStringContainsString($expected, $test);
    }

    /**
     * @see https://zendframework.com/issues/browse/ZF2-507
     */
    #[Test]
    public function defaultDateHeaderEncodingIsAlwaysAscii(): void
    {
        $this->message->setEncoding('utf-8');
        $headers = $this->message->getHeaders();
        $header  = $headers->get('date');
        $date    = date('r');
        $date    = substr($date, 0, 16);
        $test    = $header->getFieldValue();
        $test    = substr($test, 0, 16);
        static::assertSame($date, $test);
    }

    #[Test]
    public function restoreFromSerializedString(): void
    {
        $this->message->addTo('test@example.com', 'Example Test');
        $this->message->addFrom('matthew@example.com', "Matthew Weier O'Phinney");
        $this->message->addCc('list@example.com', 'Laminas Contributors List');
        $this->message->setSubject('This is a subject');
        $this->message->setBody('foo');
        $serialized      = $this->message->toString();
        $restoredMessage = Message::fromString($serialized);
        static::assertSame($serialized, $restoredMessage->toString());
    }

    #[Test]
    #[Group('45')]
    public function canRestoreFromSerializedStringWhenBodyContainsMultipleNewlines(): void
    {
        $this->message->addTo('test@example.com', 'Example Test');
        $this->message->addFrom('matthew@example.com', "Matthew Weier O'Phinney");
        $this->message->addCc('list@example.com', 'Laminas Contributors List');
        $this->message->setSubject('This is a subject');
        $this->message->setBody("foo\n\ntest");
        $serialized      = $this->message->toString();
        $restoredMessage = Message::fromString($serialized);
        static::assertSame($serialized, $restoredMessage->toString());
    }

    /**
     * @see https://zendframework.com/issues/browse/ZF-5962
     */
    #[Test]
    public function passEmptyArrayIntoSetPartsOfMimeMessageShouldReturnEmptyBodyString(): void
    {
        $mimeMessage = new MimeMessage();
        $mimeMessage->setParts([]);

        $this->message->setBody($mimeMessage);
        static::assertSame('', $this->message->getBodyText());
    }

    public static function messageRecipients(): array
    {
        return [
            'setFrom'    => ['setFrom'],
            'addFrom'    => ['addFrom'],
            'setTo'      => ['setTo'],
            'addTo'      => ['addTo'],
            'setCc'      => ['setCc'],
            'addCc'      => ['addCc'],
            'setBcc'     => ['setBcc'],
            'addBcc'     => ['addBcc'],
            'setReplyTo' => ['setReplyTo'],
            'setSender'  => ['setSender'],
        ];
    }

    #[Test]
    #[Group('ZF2015-04')]
    #[DataProvider('messageRecipients')]
    public function exceptionWhenAttemptingToSerializeMessageWithCRLFInjectionViaHeader(
        string $recipientMethod,
    ): void {
        $subject = [
            'test1',
            'Content-Type: text/html; charset = "iso-8859-1"',
            '',
            '<html><body><iframe src="http://example.com/"></iframe></body></html> <!--',
        ];
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->message->{$recipientMethod}(implode(Headers::EOL, $subject));
    }

    #[Test]
    #[Group('ZF2015-04')]
    public function detectsCRLFInjectionViaSubject(): void
    {
        $subject = [
            'test1',
            'Content-Type: text/html; charset = "iso-8859-1"',
            '',
            '<html><body><iframe src="http://example.com/"></iframe></body></html> <!--',
        ];
        $this->message->setSubject(implode(Headers::EOL, $subject));

        $serializedHeaders = $this->message->getHeaders()->toString();
        static::assertStringContainsString('example', $serializedHeaders);
        static::assertStringNotContainsString("\r\n<html>", $serializedHeaders);
    }

    #[Test]
    public function headerUnfoldingWorksAsExpectedForMultipartMessages(): void
    {
        $text              = new MimePart('Test content');
        $text->type        = Mime::TYPE_TEXT;
        $text->encoding    = Mime::ENCODING_QUOTEDPRINTABLE;
        $text->disposition = Mime::DISPOSITION_INLINE;
        $text->charset     = 'UTF-8';

        $html              = new MimePart('<b>Test content</b>');
        $html->type        = Mime::TYPE_HTML;
        $html->encoding    = Mime::ENCODING_QUOTEDPRINTABLE;
        $html->disposition = Mime::DISPOSITION_INLINE;
        $html->charset     = 'UTF-8';

        $multipartContent = new MimeMessage();
        $multipartContent->addPart($text);
        $multipartContent->addPart($html);

        $multipartPart           = new MimePart($multipartContent->generateMessage());
        $multipartPart->charset  = 'UTF-8';
        $multipartPart->type     = 'multipart/alternative';
        $multipartPart->boundary = $multipartContent->getMime()->boundary();

        $message = new MimeMessage();
        $message->addPart($multipartPart);

        $this->message->getHeaders()->addHeaderLine('Content-Transfer-Encoding', Mime::ENCODING_QUOTEDPRINTABLE);
        $this->message->setBody($message);

        $contentType = $this->message->getHeaders()->get('Content-Type');
        static::assertInstanceOf(ContentType::class, $contentType);
        static::assertStringContainsString('multipart/alternative', $contentType->getFieldValue());
        static::assertStringContainsString($multipartContent->getMime()->boundary(), $contentType->getFieldValue());
    }

    #[Test]
    #[Group('19')]
    public function canParseMultipartReport(): void
    {
        $raw     = file_get_contents(__DIR__ . '/_files/laminas-mail-19.eml');
        $message = Message::fromString($raw);
        static::assertInstanceOf(Message::class, $message);
        static::assertIsString($message->getBody());

        $headers = $message->getHeaders();
        static::assertCount(8, $headers);
        static::assertTrue($headers->has('Date'));
        static::assertTrue($headers->has('From'));
        static::assertTrue($headers->has('Message-Id'));
        static::assertTrue($headers->has('To'));
        static::assertTrue($headers->has('MIME-Version'));
        static::assertTrue($headers->has('Content-Type'));
        static::assertTrue($headers->has('Subject'));
        static::assertTrue($headers->has('Auto-Submitted'));

        $contentType = $headers->get('Content-Type');
        static::assertSame('multipart/report', $contentType->getType());
    }

    #[Test]
    public function mailHeaderContainsZeroValue(): void
    {
        $message =
            "From: someone@example.com\r\n"
            . "To: someone@example.com\r\n"
            . "Subject: plain text email example\r\n"
            . "X-Spam-Score: 0\r\n"
            . "X-Some-Value: 1\r\n"
            . "\r\n"
            . "I am a test message\r\n";

        $msg = Message::fromString($message);
        static::assertStringContainsString('X-Spam-Score: 0', $msg->toString());
    }

    /**
     * @ref CVE-2016-10033 which targeted WordPress
     */
    #[Test]
    public function secondCodeInjectionInFromHeader(): void
    {
        $message = new Message();
        $this->expectException(Exception\InvalidArgumentException::class);
        // @codingStandardsIgnoreStart
        $message->setFrom(
            'user@xenial(tmp1 -be ${run{${substr{0}{1}{$spool_directory}}usr${substr{0}{1}{$spool_directory}}bin${substr{0}{1}{$spool_directory}}touch${substr{10}{1}{$tod_log}}${substr{0}{1}{$spool_directory}}tmp${substr{0}{1}{$spool_directory}}test}}  tmp2)',
            'Sender\'s name',
        );

        // @codingStandardsIgnoreEnd
    }

    #[Test]
    public function messageSubjectFromString(): void
    {
        $rawMessage =
            'Subject: =?UTF-8?Q?Non=20=E2=80=9Cascii=E2=80=9D=20characters=20like=20accented=20?='
            . "\r\n"
            . ' =?UTF-8?Q?vowels=20=C3=B2=C3=A0=C3=B9=C3=A8=C3=A9=C3=AC?=';
        $mail = Message::fromString($rawMessage);

        static::assertStringContainsString(
            'Subject: =?UTF-8?Q?Non=20=E2=80=9Cascii=E2=80=9D=20characters=20like=20?='
                . "\r\n"
                . ' =?UTF-8?Q?accented=20vowels=20=C3=B2=C3=A0=C3=B9=C3=A8=C3=A9=C3=AC?='
                . "\r\n",
            $mail->toString(),
        );
    }

    #[Test]
    public function messageSubjectSetSubject(): void
    {
        $mail = new Message();
        $mail->setSubject('Non “ascii” characters like accented vowels òàùèéì');

        static::assertStringContainsString(
            'Subject: =?UTF-8?Q?Non=20=E2=80=9Cascii=E2=80=9D=20characters=20like=20?='
                . "\r\n"
                . ' =?UTF-8?Q?accented=20vowels=20=C3=B2=C3=A0=C3=B9=C3=A8=C3=A9=C3=AC?='
                . "\r\n",
            $mail->toString(),
        );
    }

    #[Test]
    public function correctHeaderEncodingAddHeader(): void
    {
        $mail   = new Message();
        $header = new GenericHeader('X-Test', 'Non “ascii” characters like accented vowels òàùèéì');
        $mail->getHeaders()->addHeader($header);

        static::assertStringContainsString(
            'X-Test: =?UTF-8?Q?Non=20=E2=80=9Cascii=E2=80=9D=20characters=20like=20?='
                . "\r\n"
                . ' =?UTF-8?Q?accented=20vowels=20=C3=B2=C3=A0=C3=B9=C3=A8=C3=A9=C3=AC?='
                . "\r\n",
            $mail->toString(),
        );
    }

    #[Test]
    public function correctHeaderEncodingSetHeaders(): void
    {
        $mail    = new Message();
        $header  = new GenericHeader('X-Test', 'Non “ascii” characters like accented vowels òàùèéì');
        $headers = new Headers();
        $headers->addHeader($header);
        $mail->setHeaders($headers);

        static::assertStringContainsString(
            'X-Test: =?UTF-8?Q?Non=20=E2=80=9Cascii=E2=80=9D=20characters=20like=20?='
                . "\r\n"
                . ' =?UTF-8?Q?accented=20vowels=20=C3=B2=C3=A0=C3=B9=C3=A8=C3=A9=C3=AC?='
                . "\r\n",
            $mail->toString(),
        );
    }

    #[Test]
    public function correctHeaderEncodingFromString(): void
    {
        $mail = new Message();
        $str  = 'X-Test: =?UTF-8?Q?Non=20=E2=80=9Cascii=E2=80=9D=20characters=20like=20accented=20?='
        . "\r\n"
        . ' =?UTF-8?Q?vowels=20=C3=B2=C3=A0=C3=B9=C3=A8=C3=A9=C3=AC?=';
        $header = GenericHeader::fromString($str);
        $mail->getHeaders()->addHeader($header);

        static::assertStringContainsString(
            'X-Test: =?UTF-8?Q?Non=20=E2=80=9Cascii=E2=80=9D=20characters=20like=20?='
                . "\r\n"
                . ' =?UTF-8?Q?accented=20vowels=20=C3=B2=C3=A0=C3=B9=C3=A8=C3=A9=C3=AC?=',
            $mail->toString(),
        );
    }

    #[Test]
    public function correctHeaderEncodingFromStringAndSetHeaders(): void
    {
        $mail = new Message();
        $str  = 'X-Test: =?UTF-8?Q?Non=20=E2=80=9Cascii=E2=80=9D=20characters=20like=20accented=20?='
        . "\r\n"
        . ' =?UTF-8?Q?vowels=20=C3=B2=C3=A0=C3=B9=C3=A8=C3=A9=C3=AC?=';

        $header  = GenericHeader::fromString($str);
        $headers = new Headers();
        $headers->addHeader($header);
        $mail->setHeaders($headers);

        static::assertStringContainsString(
            'X-Test: =?UTF-8?Q?Non=20=E2=80=9Cascii=E2=80=9D=20characters=20like=20?='
                . "\r\n"
                . ' =?UTF-8?Q?accented=20vowels=20=C3=B2=C3=A0=C3=B9=C3=A8=C3=A9=C3=AC?=',
            $mail->toString(),
        );
    }

    #[Test]
    public function messageSubjectEncodingWhenEncodingSetAfterTheSubject(): void
    {
        $mail = new Message();
        $mail->setSubject('hello world');
        $mail->setEncoding('UTF-8');

        static::assertSame('UTF-8', $mail->getHeaders()->get('subject')->getEncoding());
        static::assertSame(
            'Subject: =?UTF-8?Q?hello=20world?=',
            $mail->getHeaders()->get('subject')->toString(),
        );
    }

    #[Test]
    public function messageSubjectEncodingWhenEcodingSetBeforeTheSubject(): void
    {
        $mail = new Message();
        $mail->setEncoding('UTF-8');
        $mail->setSubject('hello world');

        static::assertSame('UTF-8', $mail->getHeaders()->get('subject')->getEncoding());
        static::assertSame(
            'Subject: =?UTF-8?Q?hello=20world?=',
            $mail->getHeaders()->get('subject')->toString(),
        );
    }
}
