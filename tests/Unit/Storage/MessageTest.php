<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Message as ComposedMessage;
use Contenir\Mail\Mime\Part as MimePart;
use Contenir\Mail\Mime\TransferEncoding;
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\Message;
use Contenir\Mail\Storage\Part;
use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\MimeParser;
use Contenir\Mail\Storage\Part\MultipartSplitter;
use Contenir\Mail\Tests\Unit\Storage\TestAsset\Fixtures;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;

use function fopen;
use function iterator_to_array;
use function rewind;
use function stream_get_contents;

#[CoversClass(Message::class)]
#[CoversClass(Part::class)]
#[CoversClass(Content::class)]
#[CoversClass(MimeParser::class)]
#[CoversClass(MultipartSplitter::class)]
#[Group('unit')]
final class MessageTest extends TestCase
{
    private const string MESSAGE =
        "From: Alice <alice@example.com>\r\n"
            . "To: bob@example.com, carol@example.com\r\n"
            . "Cc: dave@example.com\r\n"
            . "Reply-To: replies@example.com\r\n"
            . "Subject: =?UTF-8?Q?Gr=C3=BC=C3=9Fe?=\r\n"
            . "Date: Sun, 01 Jan 2023 10:00:00 +0000\r\n"
            . "Message-ID: <id@example.com>\r\n"
            . "\r\n"
            . 'Hello';

    #[Test]
    public function readsDecodedSubject(): void
    {
        static::assertSame('Grüße', Message::fromString(self::MESSAGE)->getSubject());
    }

    #[Test]
    public function hasNoSubjectWhenThereIsNone(): void
    {
        static::assertNull(Message::fromString("To: a@example.com\r\n\r\nx")->getSubject());
    }

    /**
     * One malformed header line does not make the message unreadable (laminas/laminas-mail#76, #221).
     */
    #[Test]
    public function readsMessageWithMalformedHeaderLine(): void
    {
        static::assertSame(
            'Hi',
            Message::fromString("Subject: Hi\r\nthis is not a header\r\nBad Name: x\r\n\r\nbody")->getSubject(),
        );
    }

    #[DataProvider('addressProvider')]
    #[Test]
    public function readsAddresses(string $method, string $expected): void
    {
        static::assertSame($expected, Message::fromString(self::MESSAGE)->{$method}()->first()?->getEmail());
    }

    #[Test]
    public function readsEveryAddress(): void
    {
        static::assertCount(2, Message::fromString(self::MESSAGE)->getTo());
    }

    #[Test]
    public function hasNoAddressesWhenHeaderIsMissing(): void
    {
        static::assertTrue(Message::fromString("Subject: x\r\n\r\nx")->getFrom()->isEmpty());
    }

    #[Test]
    public function readsDate(): void
    {
        static::assertEquals(
            new DateTimeImmutable('2023-01-01 10:00:00 +0000'),
            Message::fromString(self::MESSAGE)->getDate(),
        );
    }

    #[Test]
    public function hasNoDateWhenItIsNotValid(): void
    {
        static::assertNull(Message::fromString("Date: yesterday-ish\r\n\r\nx")->getDate());
    }

    #[Test]
    public function readsMessageId(): void
    {
        static::assertSame('id@example.com', Message::fromString(self::MESSAGE)->getMessageId());
    }

    #[Test]
    public function hasNoMessageIdWhenThereIsNone(): void
    {
        static::assertNull(Message::fromString("Subject: x\r\n\r\nx")->getMessageId());
    }

    #[Test]
    public function readsContent(): void
    {
        static::assertSame('Hello', Message::fromString(self::MESSAGE)->getContent());
    }

    #[Test]
    public function savesContentToAStream(): void
    {
        $stream = fopen('php://memory', mode: 'w+b');
        $count  = Message::fromString("Content-Transfer-Encoding: base64\r\n\r\nSGVsbG8=")->saveTo($stream);
        rewind($stream);

        static::assertSame([5, 'Hello'], [$count, stream_get_contents($stream)]);
    }

    #[Test]
    public function readsEncodedContent(): void
    {
        static::assertSame('Hello', Message::fromString(self::MESSAGE)->getEncodedContent());
    }

    #[Test]
    public function readsContentType(): void
    {
        static::assertSame('multipart/alternative', Message::fromString(Fixtures::MULTIPART)->getContentType());
    }

    #[Test]
    public function measuresBody(): void
    {
        static::assertSame(5, Message::fromString(self::MESSAGE)->getSize());
    }

    #[Test]
    public function readsParts(): void
    {
        static::assertSame('first', Message::fromString(Fixtures::MULTIPART)->getParts()[0]?->getContent());
    }

    #[Test]
    public function readsPartByNumber(): void
    {
        static::assertSame('<p>second</p>', Message::fromString(Fixtures::MULTIPART)->getPart(2)->getContent());
    }

    #[Test]
    public function countsParts(): void
    {
        static::assertSame(2, Message::fromString(Fixtures::MULTIPART)->countParts());
    }

    #[Test]
    public function isMultipart(): void
    {
        static::assertTrue(Message::fromString(Fixtures::MULTIPART)->isMultipart());
    }

    #[Test]
    public function walksParts(): void
    {
        static::assertCount(
            2,
            iterator_to_array(new RecursiveIteratorIterator(Message::fromString(Fixtures::MULTIPART))),
        );
    }

    /**
     * Forwarding: the message is written back byte for byte, encoded headers included.
     */
    #[Test]
    public function writesMessageBackAsItWasRead(): void
    {
        static::assertSame(self::MESSAGE, Message::fromString(self::MESSAGE)->toString());
    }

    #[Test]
    public function attachesToComposedMessage(): void
    {
        $stored   = Message::fromString(self::MESSAGE);
        $composed = (new ComposedMessage())->setText('See below')
            ->attach(
                new MimePart($stored->toString(), 'message/rfc822', TransferEncoding::EightBit),
            );

        static::assertStringContainsString("Subject: =?UTF-8?Q?Gr=C3=BC=C3=9Fe?=\r\n", $composed->toString());
    }

    #[DataProvider('flagProvider')]
    #[Test]
    public function hasFlagsGivenInAnySpelling(Flag|string $given, Flag|string $asked): void
    {
        static::assertTrue(Message::fromString(self::MESSAGE, [$given])->hasFlag($asked));
    }

    #[Test]
    public function lacksFlagsNotGiven(): void
    {
        static::assertFalse(Message::fromString(self::MESSAGE, [Flag::Seen])->hasFlag(Flag::Flagged));
    }

    #[Test]
    public function listsFlagsOnceEach(): void
    {
        static::assertSame(
            [Flag::Seen, '$Junk'],
            Message::fromString(self::MESSAGE, [Flag::Seen, '\seen', '$Junk', '$Junk'])->getFlags(),
        );
    }

    #[Test]
    public function hasNoFlagsByDefault(): void
    {
        static::assertSame([], Message::fromString(self::MESSAGE)->getFlags());
    }

    #[Test]
    public function wrapsAPart(): void
    {
        static::assertSame('Hello', (new Message(Part::fromString(self::MESSAGE)))->getContent());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function addressProvider(): array
    {
        return [
            'from'     => ['getFrom', 'alice@example.com'],
            'to'       => ['getTo', 'bob@example.com'],
            'cc'       => ['getCc', 'dave@example.com'],
            'reply-to' => ['getReplyTo', 'replies@example.com'],
        ];
    }

    /**
     * @return array<string, array{Flag|string, Flag|string}>
     */
    public static function flagProvider(): array
    {
        return [
            'case and case'             => [Flag::Seen, Flag::Seen],
            'imap name and case'        => ['\Seen', Flag::Seen],
            'case and imap name'        => [Flag::Seen, '\Seen'],
            'imap name in another case' => ['\SEEN', '\seen'],
            'keyword'                   => ['$Junk', '$Junk'],
            'laminas passed'            => ['Passed', Flag::Passed],
        ];
    }
}
