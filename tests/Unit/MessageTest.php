<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Exception;
use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Header\Date;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Header\Sender;
use Contenir\Mail\Header\To;
use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Part;
use DateTimeImmutable;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use TypeError;

use function array_map;
use function file_get_contents;

#[CoversClass(Message::class)]
#[Group('unit')]
final class MessageTest extends TestCase
{
    private const string FIXED_DATE = 'Date: Mon, 01 Jan 2024 00:00:00 +0000';

    private const string INJECTED_VALUE =
        "test1\r\n"
            . "Content-Type: text/html; charset = \"iso-8859-1\"\r\n"
            . "\r\n"
            . '<html><body><iframe src="http://example.com/"></iframe></body></html> <!--';

    private const string NON_ASCII_SUBJECT = 'Non “ascii” characters like accented vowels òàùèéì';

    private const string ENCODED_NON_ASCII_VALUE =
        '=?UTF-8?Q?Non=20=E2=80=9Cascii=E2=80=9D=20characters=20like=20?='
            . "\r\n"
            . ' =?UTF-8?Q?accented=20vowels=20=C3=B2=C3=A0=C3=B9=C3=A8=C3=A9=C3=AC?=';

    #[Test]
    public function isInvalidWithoutFromAddress(): void
    {
        static::assertFalse($this->makeMessage()->isValid());
    }

    #[Test]
    public function isValidOnceFromAddressIsAdded(): void
    {
        $message = $this->makeMessage()->addFrom('test@example.com');

        static::assertTrue($message->isValid());
    }

    #[Test]
    public function takesDefaultDateFromClock(): void
    {
        $message = new Message(clock: new TestAsset\FixedClock(new DateTimeImmutable('2024-02-29T13:14:15+01:00')));

        static::assertSame('Date: Thu, 29 Feb 2024 13:14:15 +0100', $message->getHeaders()->get('Date')?->toString());
    }

    #[Test]
    public function setsDateHeaderWithSystemClockByDefault(): void
    {
        static::assertInstanceOf(Date::class, (new Message())->getHeaders()->get('Date'));
    }

    #[Test]
    public function usesGivenHeadersInsteadOfDefaultDate(): void
    {
        static::assertFalse((new Message(new Headers()))->getHeaders()->has('Date'));
    }

    #[Test]
    public function returnsHeadersSetOnMessage(): void
    {
        $headers = new Headers(new GenericHeader('X-Test', 'value'));

        static::assertSame($headers, $this->makeMessage()->setHeaders($headers)->getHeaders());
    }

    #[DataProvider('addressHeaderProvider')]
    #[Test]
    public function getterReturnsEmptyListWhenHeaderIsAbsent(string $header): void
    {
        static::assertTrue($this->getAddresses($this->makeMessage(), $header)->isEmpty());
    }

    #[DataProvider('addressHeaderProvider')]
    #[Test]
    public function getterDoesNotAddHeader(string $header): void
    {
        $message = $this->makeMessage();
        $this->getAddresses($message, $header);

        static::assertFalse($message->getHeaders()->has($header));
    }

    #[DataProvider('addressHeaderProvider')]
    #[Test]
    public function addedAddressLivesInHeader(string $header): void
    {
        $message = $this->addAddresses($this->makeMessage(), $header, 'test@example.com');

        static::assertSame('test@example.com', $message->getHeaders()->get($header)?->getFieldValue());
    }

    #[DataProvider('addressHeaderProvider')]
    #[Test]
    public function addsAddressUsingEmailAndName(string $header): void
    {
        $message = $this->addAddresses($this->makeMessage(), $header, 'test@example.com', 'Example Test');

        static::assertSame(['test@example.com' => 'Example Test'], $this->namesByEmail($message, $header));
    }

    #[DataProvider('addressHeaderProvider')]
    #[Test]
    public function addsAddressUsingNameAndEmailString(string $header): void
    {
        $message = $this->addAddresses($this->makeMessage(), $header, 'Example Test <test@example.com>');

        static::assertSame(['test@example.com' => 'Example Test'], $this->namesByEmail($message, $header));
    }

    #[DataProvider('addressHeaderProvider')]
    #[Test]
    public function addsAddressObject(string $header): void
    {
        $address = new Address('test@example.com', 'Example Test');
        $message = $this->addAddresses($this->makeMessage(), $header, $address);

        static::assertSame($address, $this->getAddresses($message, $header)->first());
    }

    #[DataProvider('addressHeaderProvider')]
    #[Test]
    public function addsManyAddressesFromArray(string $header): void
    {
        $message = $this->addAddresses($this->makeMessage(), $header, [
            'test@example.com',
            'list@example.com' => 'Laminas Contributors List',
            new Address('announce@example.com', 'Laminas Announce List'),
        ]);

        static::assertSame(
            [
                'test@example.com'     => null,
                'list@example.com'     => 'Laminas Contributors List',
                'announce@example.com' => 'Laminas Announce List',
            ],
            $this->namesByEmail($message, $header),
        );
    }

    #[DataProvider('addressHeaderProvider')]
    #[Test]
    public function addingAddressListKeepsExistingAddresses(string $header): void
    {
        $message = $this->addAddresses($this->makeMessage(), $header, 'announce@example.com');
        $message = $this->addAddresses($message, $header, new AddressList(new Address('test@example.com')));

        static::assertSame(
            ['announce@example.com' => null, 'test@example.com' => null],
            $this->namesByEmail($message, $header),
        );
    }

    #[DataProvider('addressHeaderProvider')]
    #[Test]
    public function addingKnownAddressAgainKeepsOneCopy(string $header): void
    {
        $message = $this->addAddresses($this->makeMessage(), $header, 'test@example.com', 'First');
        $message = $this->addAddresses($message, $header, 'TEST@example.com', 'Second');

        static::assertSame(['test@example.com' => 'First'], $this->namesByEmail($message, $header));
    }

    #[DataProvider('addressHeaderProvider')]
    #[Test]
    public function settingAddressListReplacesExistingAddresses(string $header): void
    {
        $message = $this->addAddresses($this->makeMessage(), $header, 'announce@example.com');
        $message = $this->setAddresses($message, $header, new AddressList(new Address('test@example.com')));

        static::assertSame(['test@example.com' => null], $this->namesByEmail($message, $header));
    }

    #[DataProvider('addressHeaderProvider')]
    #[Test]
    public function settingAddressesKeepsOneHeader(string $header): void
    {
        $message = $this->setAddresses($this->makeMessage(), $header, 'first@example.com');
        $message = $this->setAddresses($message, $header, 'second@example.com');

        static::assertCount(1, $message->getHeaders()->all($header));
    }

    /**
     * @param Address|AddressList|string|iterable<int|string, Address|string|null> $addresses
     * @param array<string, null|string> $expected
     */
    #[DataProvider('toInputProvider')]
    #[Test]
    public function setToAcceptsEachInputType(
        Address|AddressList|string|iterable $addresses,
        ?string $name,
        array $expected,
    ): void {
        $message = $this->makeMessage()->setTo($addresses, $name);

        static::assertSame($expected, $this->namesByEmail($message, 'To'));
    }

    #[Test]
    public function setToAcceptsGenerator(): void
    {
        $generator = (static function (): Generator {
            yield 'first@example.com' => 'First';
            yield 'second@example.com' => null;
        })();

        $message = $this->makeMessage()->setTo($generator);

        static::assertSame(
            ['first@example.com' => 'First', 'second@example.com' => null],
            $this->namesByEmail($message, 'To'),
        );
    }

    #[Test]
    public function rejectsEmptyEntryInAddressArray(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('An address list entry is empty');

        $this->makeMessage()->setTo([null]);
    }

    #[Test]
    public function getterReflectsAddressHeaderSetDirectly(): void
    {
        $message = $this->makeMessage()->setHeader(new To(new AddressList(new Address('test@example.com'))));

        static::assertSame(['test@example.com' => null], $this->namesByEmail($message, 'To'));
    }

    #[Test]
    public function senderIsNullByDefault(): void
    {
        static::assertNull($this->makeMessage()->getSender());
    }

    #[Test]
    public function readingAbsentSenderDoesNotAddHeader(): void
    {
        $message = $this->makeMessage();
        $message->getSender();

        static::assertFalse($message->getHeaders()->has('Sender'));
    }

    #[Test]
    public function setsSenderFromEmail(): void
    {
        $message = $this->makeMessage()->setSender('test@example.com');

        static::assertSame('test@example.com', $message->getSender()?->getEmail());
    }

    #[Test]
    public function setsSenderWithName(): void
    {
        $message = $this->makeMessage()->setSender('test@example.com', 'Example Test');

        static::assertSame('Example Test', $message->getSender()?->getName());
    }

    #[Test]
    public function setsSenderFromAddressObject(): void
    {
        $sender = new Address('test@example.com');

        static::assertSame($sender, $this->makeMessage()->setSender($sender)->getSender());
    }

    #[Test]
    public function settingSenderReplacesSenderHeader(): void
    {
        $message = $this->makeMessage()
            ->setSender('first@example.com')
            ->setSender('second@example.com');

        static::assertSame(
            ['Sender: second@example.com'],
            array_map(static fn($header): string => $header->toString(), $message->getHeaders()->all('Sender')),
        );
    }

    #[Test]
    public function readsSenderFromSenderHeader(): void
    {
        $address = new Address('test@example.com', 'Example Test');
        $message = $this->makeMessage()->setHeader(new Sender($address));

        static::assertSame($address, $message->getSender());
    }

    #[Test]
    public function subjectIsNullByDefault(): void
    {
        static::assertNull($this->makeMessage()->getSubject());
    }

    #[Test]
    public function setsSubject(): void
    {
        static::assertSame('test subject', $this->makeMessage()->setSubject('test subject')->getSubject());
    }

    #[Test]
    public function settingSubjectReplacesExistingSubject(): void
    {
        $message = $this->makeMessage()
            ->setSubject('test subject')
            ->setSubject('new subject');

        static::assertSame('Subject: new subject', $message->getHeaders()->get('Subject')?->toString());
    }

    #[Test]
    public function subjectLivesInHeader(): void
    {
        $message = $this->makeMessage()->setSubject('test subject');

        static::assertSame('test subject', $message->getHeaders()->get('Subject')?->getFieldValue());
    }

    #[Test]
    public function returnsDecodedNonAsciiSubject(): void
    {
        $message = $this->makeMessage()->setSubject(self::NON_ASCII_SUBJECT);

        static::assertSame(self::NON_ASCII_SUBJECT, $message->getSubject());
    }

    #[Test]
    public function encodesNonAsciiSubjectOnTheWire(): void
    {
        $message = $this->makeMessage()->setSubject(self::NON_ASCII_SUBJECT);

        static::assertStringContainsString(
            'Subject: ' . self::ENCODED_NON_ASCII_VALUE . "\r\n",
            $message->toString(),
        );
    }

    #[Test]
    public function reEncodesSubjectParsedFromString(): void
    {
        $rawMessage =
            'Subject: =?UTF-8?Q?Non=20=E2=80=9Cascii=E2=80=9D=20characters=20like=20accented=20?='
            . "\r\n"
            . ' =?UTF-8?Q?vowels=20=C3=B2=C3=A0=C3=B9=C3=A8=C3=A9=C3=AC?=';

        static::assertSame(
            'Subject: ' . self::ENCODED_NON_ASCII_VALUE,
            Message::fromString($rawMessage)->getHeaders()->get('Subject')?->toString(),
        );
    }

    /**
     * Headers choose their own encoding: plain ASCII stays readable whatever the charset of the body.
     */
    #[Test]
    public function leavesAsciiSubjectUnencodedWithNonAsciiTextBody(): void
    {
        $message = $this->makeMessage()->setText('Grüße')->setSubject('hello world');

        static::assertSame('Subject: hello world', $message->getHeaders()->get('Subject')?->toString());
    }

    #[Test]
    public function leavesAsciiHeadersUnencodedWithNonAsciiTextBody(): void
    {
        $message = $this->makeMessage()
            ->addTo('test@example.com', 'Laminas DevTeam')
            ->addFrom('matthew@example.com', "Matthew Weier O'Phinney")
            ->addCc('list@example.com', 'Laminas Contributors List')
            ->addBcc('devs@example.com', 'Laminas CR Team')
            ->setSubject('This is a subject')
            ->setText('Grüße');

        static::assertSame(
            self::FIXED_DATE
                . "\r\n"
                . "To: Laminas DevTeam <test@example.com>\r\n"
                . "From: Matthew Weier O'Phinney <matthew@example.com>\r\n"
                . "Cc: Laminas Contributors List <list@example.com>\r\n"
                . "Bcc: Laminas CR Team <devs@example.com>\r\n"
                . "Subject: This is a subject\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=\"UTF-8\"\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n",
            $message->getHeaders()->toString(),
        );
    }

    #[DataProvider('addressHeaderProvider')]
    #[Test]
    public function encodesNonAsciiDisplayNameOnTheWire(string $header): void
    {
        $message = $this->addAddresses($this->makeMessage(), $header, 'test@example.com', 'Jösé Ünïcode');

        static::assertSame(
            "{$header}: =?UTF-8?Q?J=C3=B6s=C3=A9=20=C3=9Cn=C3=AFcode?= <test@example.com>",
            $message->getHeaders()->get($header)?->toString(),
        );
    }

    /**
     * @see https://zendframework.com/issues/browse/ZF2-507
     */
    #[Test]
    public function dateHeaderStaysAsciiWithNonAsciiTextBody(): void
    {
        $message = $this->makeMessage()->setText('Grüße');

        static::assertSame(self::FIXED_DATE, $message->getHeaders()->get('Date')?->toString());
    }

    #[Test]
    public function setHeaderReplacesHeadersOfSameName(): void
    {
        $message = $this->makeMessage()
            ->addHeader(new GenericHeader('X-Test', 'first'))
            ->addHeader(new GenericHeader('X-Test', 'second'))
            ->setHeader(new GenericHeader('X-Test', 'third'));

        static::assertSame(['third'], $this->fieldValues($message, 'X-Test'));
    }

    #[Test]
    public function setHeaderKeepsPositionOfReplacedHeader(): void
    {
        $message = $this->makeMessage()
            ->addHeader(new GenericHeader('X-First', 'one'))
            ->addHeader(new GenericHeader('X-Second', 'two'))
            ->setHeader(new GenericHeader('X-First', 'three'));

        static::assertSame(
            self::FIXED_DATE . "\r\nX-First: three\r\nX-Second: two\r\n",
            $message->getHeaders()->toString(),
        );
    }

    #[Test]
    public function addHeaderKeepsHeadersOfSameName(): void
    {
        $message = $this->makeMessage()
            ->addHeader(new GenericHeader('X-Test', 'first'))
            ->addHeader(new GenericHeader('X-Test', 'second'));

        static::assertSame(['first', 'second'], $this->fieldValues($message, 'X-Test'));
    }

    #[Test]
    public function removeHeaderRemovesEveryHeaderOfThatName(): void
    {
        $message = $this->makeMessage()
            ->addHeader(new GenericHeader('X-Test', 'first'))
            ->addHeader(new GenericHeader('X-Test', 'second'))
            ->removeHeader('x-test');

        static::assertFalse($message->getHeaders()->has('X-Test'));
    }

    #[Test]
    public function removeHeaderKeepsOtherHeaders(): void
    {
        $message = $this->makeMessage()
            ->addHeader(new GenericHeader('X-Test', 'first'))
            ->removeHeader('X-Test');

        static::assertSame(self::FIXED_DATE . "\r\n", $message->getHeaders()->toString());
    }

    #[Test]
    public function removingAbsentHeaderLeavesHeadersUnchanged(): void
    {
        $message = $this->makeMessage()->removeHeader('X-Missing');

        static::assertSame(self::FIXED_DATE . "\r\n", $message->getHeaders()->toString());
    }

    #[Test]
    public function encodesNonAsciiValueOfAddedHeader(): void
    {
        $message = $this->makeMessage()->addHeader(new GenericHeader('X-Test', self::NON_ASCII_SUBJECT));

        static::assertStringContainsString(
            'X-Test: ' . self::ENCODED_NON_ASCII_VALUE . "\r\n",
            $message->toString(),
        );
    }

    #[Test]
    public function encodesNonAsciiValueOfHeadersSetOnMessage(): void
    {
        $message = $this->makeMessage()->setHeaders(new Headers(new GenericHeader('X-Test', self::NON_ASCII_SUBJECT)));

        static::assertStringContainsString(
            'X-Test: ' . self::ENCODED_NON_ASCII_VALUE . "\r\n",
            $message->toString(),
        );
    }

    #[Test]
    public function reEncodesHeaderParsedFromString(): void
    {
        $header = GenericHeader::fromString(
            'X-Test: =?UTF-8?Q?Non=20=E2=80=9Cascii=E2=80=9D=20characters=20like=20accented=20?='
                . "\r\n"
                . ' =?UTF-8?Q?vowels=20=C3=B2=C3=A0=C3=B9=C3=A8=C3=A9=C3=AC?=',
        );
        $message = $this->makeMessage()->addHeader($header);

        static::assertStringContainsString('X-Test: ' . self::ENCODED_NON_ASCII_VALUE, $message->toString());
    }

    #[Test]
    public function bodyIsNullByDefault(): void
    {
        static::assertNull($this->makeMessage()->getBody());
    }

    #[Test]
    public function bodyTextIsEmptyWithoutBody(): void
    {
        static::assertSame('', $this->makeMessage()->getBodyText());
    }

    #[Test]
    public function setsBodyFromString(): void
    {
        static::assertSame('body', $this->makeMessage()->setBody('body')->getBody());
    }

    #[Test]
    public function setsBodyFromStringableObject(): void
    {
        $object = new TestAsset\StringSerializableObject('body');

        static::assertSame($object, $this->makeMessage()->setBody($object)->getBody());
    }

    #[Test]
    public function bodyTextOfStringableObjectIsItsString(): void
    {
        $message = $this->makeMessage()->setBody(new TestAsset\StringSerializableObject('body'));

        static::assertSame('body', $message->getBodyText());
    }

    #[Test]
    public function setsBodyFromMimePart(): void
    {
        $body = Part::html('<b>foo</b>');

        static::assertSame($body, $this->makeMessage()->setBody($body)->getBody());
    }

    #[Test]
    public function setsNullBody(): void
    {
        static::assertNull($this->makeMessage()->setBody('body')->setBody(null)->getBody());
    }

    #[DataProvider('invalidBodyProvider')]
    #[Test]
    public function rejectsBodyOfUnsupportedType(mixed $body): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('must be of type Stringable|Contenir\Mail\Mime\PartInterface|string|null');

        /** @psalm-suppress MixedArgument */
        $this->makeMessage()->setBody($body);
    }

    #[Test]
    public function writesPinnedWireOutput(): void
    {
        $message = $this->makeMessage()
            ->addTo('test@example.com', 'Example Test')
            ->addFrom('matthew@example.com', "Matthew Weier O'Phinney")
            ->addCc('list@example.com', 'Ünïcode Name')
            ->setSubject(self::NON_ASCII_SUBJECT)
            ->setBody('foo');

        static::assertSame(
            self::FIXED_DATE
                . "\r\n"
                . "To: Example Test <test@example.com>\r\n"
                . "From: Matthew Weier O'Phinney <matthew@example.com>\r\n"
                . "Cc: =?UTF-8?Q?=C3=9Cn=C3=AFcode=20Name?= <list@example.com>\r\n"
                . 'Subject: '
                . self::ENCODED_NON_ASCII_VALUE
                . "\r\n"
                . "\r\n"
                . 'foo',
            $message->toString(),
        );
    }

    #[Test]
    public function writesHeadersAndBlankLineWithoutBody(): void
    {
        static::assertSame(self::FIXED_DATE . "\r\n\r\n", $this->makeMessage()->toString());
    }

    #[DataProvider('serializedBodyProvider')]
    #[Test]
    public function restoresFromSerializedString(string $body): void
    {
        $message = $this->makeMessage()
            ->addTo('test@example.com', 'Example Test')
            ->addFrom('matthew@example.com', "Matthew Weier O'Phinney")
            ->addCc('list@example.com', 'Laminas Contributors List')
            ->setSubject('This is a subject')
            ->setBody($body);

        static::assertSame($message->toString(), Message::fromString($message->toString())->toString());
    }

    #[Test]
    public function writesParsedMessageBackByteForByte(): void
    {
        $raw =
            "DKIM-Signature: v=1; a=rsa-sha256; d=example.org;\r\n\th=from:subject; b=abc\r\n"
            . "from: Example <a@example.org>\r\n"
            . "Subject: =?ISO-8859-1?Q?Gr=FC=DFe?=\r\n"
            . "\r\n"
            . "Hello\r\n";

        static::assertSame($raw, Message::fromString($raw)->toString());
    }

    #[Test]
    public function writesChangedHeaderOfParsedMessageFromItsValue(): void
    {
        $message = Message::fromString("Subject: =?ISO-8859-1?Q?Gr=FC=DFe?=\r\nX-Id: 1\r\n\r\nHello")->setSubject('Hi');

        static::assertSame("Subject: Hi\r\nX-Id: 1\r\n\r\nHello", $message->toString());
    }

    #[Test]
    public function parsedMessageHasNoDateWhenRawMessageHasNone(): void
    {
        $message = Message::fromString("Subject: Hello\r\n\r\nbody");

        static::assertFalse($message->getHeaders()->has('Date'));
    }

    #[Test]
    public function parsedMessageKeepsBody(): void
    {
        static::assertSame('body', Message::fromString("Subject: Hello\r\n\r\nbody")->getBody());
    }

    #[Test]
    public function parsedMessageExposesAddressesThroughGetters(): void
    {
        $message = Message::fromString("To: Example Test <test@example.com>\r\n\r\nbody");

        static::assertSame(['test@example.com' => 'Example Test'], $this->namesByEmail($message, 'To'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedHeaderProvider(): array
    {
        return [
            'Sender without an address' => ['Sender', 'foo'],
            'From without an address'   => ['From', '@@@'],
            'To without an address'     => ['To', '<<<'],
            'Date that is not a date'   => ['Date', 'not a date'],
            'Content-Type without type' => ['Content-Type', 'nonsense'],
            'Message-ID with a space'   => ['Message-ID', '<a b@example.com>'],
        ];
    }

    #[DataProvider('malformedHeaderProvider')]
    #[Test]
    public function keepsMalformedHeaderAsGenericHeaderAndParsesTheRest(string $name, string $value): void
    {
        $message = Message::fromString(
            "Subject: Hello\r\n{$name}: {$value}\r\nX-Other: yes\r\n\r\nbody",
        );
        $header = $message->getHeaders()->get($name);

        static::assertSame(
            [GenericHeader::class, $value, 'Hello', 'yes', 'body'],
            [
                null === $header ? null : $header::class,
                $header?->getFieldValue(),
                $message->getSubject(),
                $message->getHeaders()->get('X-Other')?->getFieldValue(),
                $message->getBody(),
            ],
        );
    }

    #[DataProvider('malformedHeaderProvider')]
    #[Test]
    public function writesMessageWithMalformedHeaderBackByteForByte(string $name, string $value): void
    {
        $raw = "Subject: Hello\r\n{$name}: {$value}\r\nX-Other: yes\r\n\r\nbody";

        static::assertSame($raw, Message::fromString($raw)->toString());
    }

    #[DataProvider('multipartReportHeaderProvider')]
    #[Test]
    #[Group('19')]
    public function parsesHeadersOfMultipartReport(string $header): void
    {
        static::assertTrue($this->parseMultipartReport()->getHeaders()->has($header));
    }

    #[Test]
    #[Group('19')]
    public function parsesEveryHeaderOfMultipartReport(): void
    {
        static::assertCount(8, $this->parseMultipartReport()->getHeaders());
    }

    #[Test]
    #[Group('19')]
    public function keepsBodyOfMultipartReportAsText(): void
    {
        static::assertIsString($this->parseMultipartReport()->getBody());
    }

    #[Test]
    #[Group('19')]
    public function parsesContentTypeOfMultipartReport(): void
    {
        $contentType = $this->parseMultipartReport()->getHeaders()->get('Content-Type');

        static::assertInstanceOf(ContentType::class, $contentType);
        static::assertSame('multipart/report', $contentType->getType());
    }

    #[Test]
    public function keepsHeaderWithZeroValue(): void
    {
        $message = Message::fromString(
            "From: someone@example.com\r\n"
                . "To: someone@example.com\r\n"
                . "Subject: plain text email example\r\n"
                . "X-Spam-Score: 0\r\n"
                . "X-Some-Value: 1\r\n"
                . "\r\n"
                . "I am a test message\r\n",
        );

        static::assertStringContainsString("X-Spam-Score: 0\r\n", $message->toString());
    }

    #[DataProvider('crlfInjectionProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsCrlfInjectionViaAddress(string $method, string $value, ?string $name, string $error): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage($error);

        $this->makeMessage()->{$method}($value, $name);
    }

    #[DataProvider('injectedLineProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function encodesCrlfInSubjectInsteadOfStartingNewLines(string $injectedLine): void
    {
        $message = $this->makeMessage()->setSubject(self::INJECTED_VALUE);

        static::assertStringNotContainsString($injectedLine, $message->getHeaders()->toString());
    }

    #[Group('ZF2015-04')]
    #[Test]
    public function keepsInjectedSubjectAsOneDecodedValue(): void
    {
        $message = $this->makeMessage()->setSubject(self::INJECTED_VALUE);

        static::assertSame(self::INJECTED_VALUE, $message->getSubject());
    }

    /**
     * @ref CVE-2016-10033 which targeted WordPress
     */
    #[Test]
    public function rejectsShellCodeInFromAddress(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a valid hostname for the email address');

        // @codingStandardsIgnoreStart
        $this->makeMessage()->setFrom(
            'user@xenial(tmp1 -be ${run{${substr{0}{1}{$spool_directory}}usr${substr{0}{1}{$spool_directory}}bin${substr{0}{1}{$spool_directory}}touch${substr{10}{1}{$tod_log}}${substr{0}{1}{$spool_directory}}tmp${substr{0}{1}{$spool_directory}}test}}  tmp2)',
            "Sender's name",
        );

        // @codingStandardsIgnoreEnd
    }

    /**
     * @return array<string, array{string}>
     */
    public static function addressHeaderProvider(): array
    {
        return [
            'From'     => ['From'],
            'To'       => ['To'],
            'Cc'       => ['Cc'],
            'Bcc'      => ['Bcc'],
            'Reply-To' => ['Reply-To'],
        ];
    }

    /**
     * @return array<string, array{Address|AddressList|string|iterable<int|string, Address|string|null>, ?string, array<string, null|string>}>
     */
    public static function toInputProvider(): array
    {
        return [
            'bare email string'        => ['test@example.com', null, ['test@example.com' => null]],
            'name and email string'    => [
                'Example Test <test@example.com>',
                null,
                ['test@example.com' => 'Example Test'],
            ],
            'email with name argument' => [
                'test@example.com',
                'Example Test',
                ['test@example.com' => 'Example Test'],
            ],
            'address object'           => [
                new Address('test@example.com', 'Example Test'),
                null,
                ['test@example.com' => 'Example Test'],
            ],
            'address list'             => [
                new AddressList(new Address('first@example.com'), new Address('second@example.com', 'Second')),
                null,
                ['first@example.com' => null, 'second@example.com' => 'Second'],
            ],
            'list of address strings'  => [
                ['first@example.com', 'Second <second@example.com>'],
                null,
                ['first@example.com' => null, 'second@example.com' => 'Second'],
            ],
            'email to name map'        => [
                ['first@example.com' => 'First', 'second@example.com' => null],
                null,
                ['first@example.com' => 'First', 'second@example.com' => null],
            ],
            'list of address objects'  => [
                [new Address('first@example.com'), new Address('second@example.com', 'Second')],
                null,
                ['first@example.com' => null, 'second@example.com' => 'Second'],
            ],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidBodyProvider(): array
    {
        return [
            'array'  => [['foo']],
            'true'   => [true],
            'false'  => [false],
            'object' => [new stdClass()],
            'int'    => [42],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function serializedBodyProvider(): array
    {
        return [
            'single line'       => ['foo'],
            'multiple newlines' => ["foo\n\ntest"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function multipartReportHeaderProvider(): array
    {
        return [
            'Date'           => ['Date'],
            'From'           => ['From'],
            'Message-Id'     => ['Message-Id'],
            'To'             => ['To'],
            'MIME-Version'   => ['MIME-Version'],
            'Content-Type'   => ['Content-Type'],
            'Subject'        => ['Subject'],
            'Auto-Submitted' => ['Auto-Submitted'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedLineProvider(): array
    {
        return [
            'injected header line' => ["\r\nContent-Type:"],
            'injected blank line'  => ["\r\n\r\n"],
            'injected body line'   => ["\r\n<html>"],
        ];
    }

    /**
     * @return array<string, array{string, string, ?string, string}>
     */
    public static function crlfInjectionProvider(): array
    {
        $methods = [
            'setFrom',
            'addFrom',
            'setTo',
            'addTo',
            'setCc',
            'addCc',
            'setBcc',
            'addBcc',
            'setReplyTo',
            'addReplyTo',
        ];

        $cases = [];
        foreach ($methods as $method) {
            $cases["{$method} with injected address"] = [$method, self::INJECTED_VALUE, null, 'Invalid address format'];
            $cases["{$method} with injected name"]    = [
                $method,
                'test@example.com',
                "Name\r\nBcc: attacker@example.net",
                'CRLF injection detected',
            ];
        }

        $cases['setSender with injected address'] = [
            'setSender',
            self::INJECTED_VALUE,
            null,
            'CRLF injection detected',
        ];
        $cases['setSender with injected name'] = [
            'setSender',
            'test@example.com',
            "Name\r\nBcc: attacker@example.net",
            'CRLF injection detected',
        ];

        return $cases;
    }

    private function makeMessage(): Message
    {
        return new Message(new Headers(new Date(new DateTimeImmutable('2024-01-01T00:00:00Z'))));
    }

    private function parseMultipartReport(): Message
    {
        return Message::fromString((string) file_get_contents(__DIR__ . '/_files/laminas-mail-19.eml'));
    }

    /**
     * @param Address|AddressList|string|iterable<int|string, Address|string|null> $addresses
     */
    private function addAddresses(
        Message $message,
        string $header,
        Address|AddressList|string|iterable $addresses,
        ?string $name = null,
    ): Message {
        return match ($header) {
            'From'  => $message->addFrom($addresses, $name),
            'To'    => $message->addTo($addresses, $name),
            'Cc'    => $message->addCc($addresses, $name),
            'Bcc'   => $message->addBcc($addresses, $name),
            default => $message->addReplyTo($addresses, $name),
        };
    }

    /**
     * @param Address|AddressList|string|iterable<int|string, Address|string|null> $addresses
     */
    private function setAddresses(
        Message $message,
        string $header,
        Address|AddressList|string|iterable $addresses,
    ): Message {
        return match ($header) {
            'From'  => $message->setFrom($addresses),
            'To'    => $message->setTo($addresses),
            'Cc'    => $message->setCc($addresses),
            'Bcc'   => $message->setBcc($addresses),
            default => $message->setReplyTo($addresses),
        };
    }

    private function getAddresses(Message $message, string $header): AddressList
    {
        return match ($header) {
            'From'  => $message->getFrom(),
            'To'    => $message->getTo(),
            'Cc'    => $message->getCc(),
            'Bcc'   => $message->getBcc(),
            default => $message->getReplyTo(),
        };
    }

    /**
     * @return array<string, null|string>
     */
    private function namesByEmail(Message $message, string $header): array
    {
        $names = [];
        foreach ($this->getAddresses($message, $header) as $address) {
            $names[$address->getEmail()] = $address->getName();
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function fieldValues(Message $message, string $name): array
    {
        return array_map(
            static fn($header): string => $header->getFieldValue(),
            $message->getHeaders()->all($name),
        );
    }

    #[DataProvider('nonStringAddressesProvider')]
    #[Test]
    public function rejectsDisplayNameWithAnythingButAnEmailString(Address|AddressList|array $addresses): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('A display name can only be given with a single e-mail address');

        (new Message())->setTo($addresses, 'Name');
    }

    /**
     * @return array<string, array{Address|AddressList|list<string>}>
     */
    public static function nonStringAddressesProvider(): array
    {
        return [
            'address'      => [new Address('first@example.com')],
            'address list' => [new AddressList(new Address('first@example.com'))],
            'list'         => [['first@example.com']],
        ];
    }

    #[Test]
    public function keepsTheAddressListItIsGiven(): void
    {
        $list = new AddressList(new Address('first@example.com'));

        static::assertSame(
            $list->toArray(),
            (new Message())->setTo($list)
                ->getTo()
                ->toArray(),
        );
    }
}
