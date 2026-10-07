<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Address\AddressInterface;
use Contenir\Mail\Exception as MailException;
use Contenir\Mail\Header\HeaderInterface;
use Contenir\Mail\Header\To;
use Contenir\Mail\Headers;
use Contenir\Mail\Mime;
use Contenir\Mail\Mime\Exception as MimeException;
use Contenir\Mail\Storage;
use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\Message;
use Exception as GeneralException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;

use function file_get_contents;
use function fopen;
use function implode;
use function substr;
use function var_export;

#[CoversClass(Message::class)]
#[CoversClass(Headers::class)]
class MessageTest extends TestCase
{
    /** @var string */
    protected $file;
    /** @var string */
    protected $file2;

    public function setUp(): void
    {
        $this->file  = __DIR__ . '/../_files/mail.eml';
        $this->file2 = __DIR__ . '/../_files/mail_multi_to.eml';
    }

    #[Test]
    public function invalidFile(): void
    {
        $this->expectException(GeneralException::class);
        new Message(['file' => '/this/file/does/not/exists']);
    }

    #[Test]
    #[DataProvider('filesProvider')]
    public function isMultipart(array $params): void
    {
        $message = new Message($params);
        static::assertTrue($message->isMultipart());
    }

    #[Test]
    #[DataProvider('filesProvider')]
    public function getHeader(array $params): void
    {
        $message = new Message($params);
        static::assertSame($message->subject, 'multipart');
    }

    #[Test]
    #[DataProvider('filesProvider')]
    public function getToHeader(array $params): void
    {
        $message = new Message($params);
        /** @var HeaderInterface $toHeader */
        $toHeader = $message->getHeader('To');
        static::assertSame('foo@example.com', $toHeader->getFieldValue());
    }

    #[Test]
    #[DataProvider('filesProvider')]
    public function getDecodedHeader(array $params): void
    {
        $message = new Message($params);
        static::assertSame('Peter Müller <peter-mueller@example.com>', $message->from);
    }

    #[Test]
    #[DataProvider('filesProvider')]
    public function getHeaderAsArray(array $params): void
    {
        $message = new Message($params);
        static::assertSame(['multipart'], $message->getHeader('subject', 'array'), 'getHeader() value not match');
    }

    #[Test]
    public function getFirstPart(): void
    {
        $message = new Message(['file' => $this->file]);

        static::assertSame(substr($message->getPart(1)->getContent(), 0, 14), 'The first part');
    }

    #[Test]
    public function getFirstPartTwice(): void
    {
        $message = new Message(['file' => $this->file]);

        $message->getPart(1);
        static::assertSame(substr($message->getPart(1)->getContent(), 0, 14), 'The first part');
    }

    #[Test]
    public function getWrongPart(): void
    {
        $this->expectException(GeneralException::class);
        $message = new Message(['file' => $this->file]);
        $message->getPart(-1);
    }

    #[Test]
    public function noHeaderMessage(): void
    {
        $message = new Message(['file' => __FILE__]);

        static::assertSame(substr($message->getContent(), 0, 5), '<?php');

        $raw     = file_get_contents(__FILE__);
        $raw     = "\t{$raw}";
        $message = new Message(['raw' => $raw]);

        static::assertSame(substr($message->getContent(), 0, 6), "\t<?php");
    }

    /**
     * after pull/86 messageId gets double braces
     *
     * @see https://github.com/zendframework/zend-mail/pull/86
     * @see https://github.com/zendframework/zend-mail/pull/156
     */
    #[Test]
    public function messageIdHeader(): void
    {
        $message   = new Message(['file' => $this->file]);
        $messageId = $message->messageId;
        static::assertSame('<CALTvGe4_oYgf9WsYgauv7qXh2-6=KbPLExmJNG7fCs9B=1nOYg@mail.example.com>', $messageId);
    }

    #[Test]
    public function multipleHeader(): void
    {
        $raw     = file_get_contents($this->file);
        $raw     = "sUBject: test\r\nSubJect: test2\r\n{$raw}";
        $message = new Message(['raw' => $raw]);

        static::assertSame(
            'test' . Mime\Mime::LINEEND . 'test2' . Mime\Mime::LINEEND . 'multipart',
            $message->getHeader('subject', 'string'),
        );

        static::assertSame(
            ['test', 'test2', 'multipart'],
            $message->getHeader('subject', 'array'),
        );
    }

    #[Test]
    public function allowWhitespaceInEmptySingleLineHeader(): void
    {
        $src =
            "From: user@example.com\n"
            . "To: userpal@example.net\n"
            . "Subject: This is your reminder\n  \n  about the football game tonight\n"
            . "Date: Wed, 20 Oct 2010 20:53:35 -0400\n\n"
            . "Don't forget to meet us for the tailgate party!\n";
        $message = new Message(['raw' => $src]);

        static::assertSame(
            'This is your reminder about the football game tonight',
            $message->getHeader('subject', 'string'),
        );
    }

    #[Test]
    public function allowWhitespaceInEmptyMultiLineHeader(): void
    {
        $src =
            "From: user@example.com\nTo: userpal@example.net\n"
            . "Subject: This is your reminder\n  \n \n"
            . "  about the football game tonight\n"
            . "Date: Wed, 20 Oct 2010 20:53:35 -0400\n\n"
            . "Don't forget to meet us for the tailgate party!\n";
        $message = new Message(['raw' => $src]);

        static::assertSame(
            'This is your reminder about the football game tonight',
            $message->getHeader('subject', 'string'),
        );
    }

    #[Test]
    public function contentTypeDecode(): void
    {
        $message = new Message(['file' => $this->file]);

        static::assertSame(
            Mime\Decode::splitContentType($message->ContentType),
            ['type' => 'multipart/alternative', 'boundary' => 'crazy-multipart'],
        );
    }

    #[Test]
    public function splitEmptyMessage(): void
    {
        static::assertSame(Mime\Decode::splitMessageStruct('', 'xxx'), null);
    }

    #[Test]
    public function splitInvalidMessage(): void
    {
        $this->expectException(MimeException\ExceptionInterface::class);
        Mime\Decode::splitMessageStruct("--xxx\n", 'xxx');
    }

    #[Test]
    public function invalidMailHandler(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        new Message(['handler' => 1]);
    }

    #[Test]
    public function missingId(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $mail = new Storage\Mbox(['filename' => __DIR__ . '/../_files/test.mbox/INBOX']);
        new Message(['handler' => $mail]);
    }

    #[Test]
    public function iterator(): void
    {
        $message = new Message(['file' => $this->file]);
        foreach (new RecursiveIteratorIterator($message) as $num => $part) {
            if (1 != $num) {
                continue;
            }

            // explicit call of __toString() needed for PHP < 5.2
            static::assertSame(substr($part->__toString(), 0, 14), 'The first part');
        }
        static::assertSame($part->contentType, 'text/x-vertical');
    }

    #[Test]
    public function decodeString(): void
    {
        $is = Mime\Decode::decodeQuotedPrintable('=?UTF-8?Q?"Peter M=C3=BCller"?= <peter-mueller@example.com>');
        static::assertSame('"Peter Müller" <peter-mueller@example.com>', $is);
    }

    #[Test]
    public function splitHeader(): void
    {
        $header = 'foo; x=y; y="x"';
        static::assertSame(Mime\Decode::splitHeaderField($header), ['foo', 'x' => 'y', 'y' => 'x']);
        static::assertSame(Mime\Decode::splitHeaderField($header, 'x'), 'y');
        static::assertSame(Mime\Decode::splitHeaderField($header, 'y'), 'x');
        static::assertSame(Mime\Decode::splitHeaderField($header, 'foo', 'foo'), 'foo');
        static::assertSame(Mime\Decode::splitHeaderField($header, 'foo'), null);
    }

    #[Test]
    public function splitInvalidHeader(): void
    {
        $this->expectException(MimeException\ExceptionInterface::class);
        $header = '';
        Mime\Decode::splitHeaderField($header);
    }

    #[Test]
    public function splitMessage(): void
    {
        $header   = 'Test: test';
        $body     = 'body';
        $newlines = ["\r\n", "\n\r", "\n", "\r"];

        $decodedBody = null; // "Declare" variable before first "read" usage to avoid IDEs warning
        $decodedHeaders = null; // "Declare" variable before first "read" usage to avoid IDEs warning

        foreach ($newlines as $contentEol) {
            foreach ($newlines as $decodeEol) {
                $content = $header . $contentEol . $contentEol . $body;
                Mime\Decode::splitMessage($content, $decodedHeaders, $decodedBody, $decodeEol);
                static::assertSame(['Test' => 'test'], $decodedHeaders->toArray());
                static::assertSame($body, $decodedBody);
            }
        }
    }

    #[Test]
    public function topLines(): void
    {
        $message = new Message(['headers' => file_get_contents($this->file)]);
        static::assertStringStartsWith('multipart message', $message->getToplines());
    }

    #[Test]
    public function noContent(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $message = new Message(['raw' => 'Subject: test']);
        $message->getContent();
    }

    #[Test]
    public function emptyHeader(): void
    {
        $message = new Message([]);
        static::assertSame([], $message->getHeaders()->toArray());

        $message = new Message([]);

        $this->expectException(MailException\InvalidArgumentException::class);
        $message->subject;
    }

    #[Test]
    public function wrongHeaderType(): void
    {
        // @codingStandardsIgnoreStart
        $badMessage = unserialize(
            "O:29:\"Contenir\Mail\Storage\Message\":9:{s:8:\"\x00*\x00flags\";a:0:{}s:10:\"\x00*\x00headers\";s:16:\"Yellow submarine\";s:10:\"\x00*\x00content\";N;s:11:\"\x00*\x00topLines\";s:0:\"\";s:8:\"\x00*\x00parts\";a:0:{}s:13:\"\x00*\x00countParts\";N;s:15:\"\x00*\x00iterationPos\";i:1;s:7:\"\x00*\x00mail\";N;s:13:\"\x00*\x00messageNum\";i:0;}",
        );
        // @codingStandardsIgnoreEnd

        $this->expectException(MailException\RuntimeException::class);
        $badMessage->getHeaders();
    }

    #[Test]
    public function emptyBody(): void
    {
        $message = new Message([]);
        $part    = null;
        try {
            $part = $message->getPart(1);
        } catch (Exception\RuntimeException) {
            // ok
        }
        if ($part) {
            static::fail('no exception raised while getting part from empty message');
        }

        $message = new Message([]);
        static::assertSame(0, $message->countParts());
    }

    /**
     * @see https://zendframework.com/issues/browse/ZF-5209
     */
    #[Test]
    public function checkingHasHeaderFunctionality(): void
    {
        $message = new Message(['headers' => ['subject' => 'foo']]);

        static::assertTrue($message->getHeaders()->has('subject'));
        static::assertTrue(isset($message->subject));
        static::assertTrue($message->getHeaders()->has('SuBject'));
        static::assertTrue(isset($message->suBjeCt));
        static::assertFalse($message->getHeaders()->has('From'));
    }

    #[Test]
    public function wrongMultipart(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $message = new Message(['raw' => "Content-Type: multipart/mixed\r\n\r\ncontent"]);
        $message->getPart(1);
    }

    #[Test]
    public function lateFetch(): void
    {
        $mail = new Storage\Mbox(['filename' => __DIR__ . '/../_files/test.mbox/INBOX']);

        $message = new Message(['handler' => $mail, 'id' => 5]);
        static::assertSame($message->countParts(), 2);
        static::assertSame($message->countParts(), 2);

        $message = new Message(['handler' => $mail, 'id' => 5]);
        static::assertSame($message->subject, 'multipart');

        $message = new Message(['handler' => $mail, 'id' => 5]);
        static::assertStringStartsWith('multipart message', $message->getContent());
    }

    #[Test]
    public function manualIterator(): void
    {
        $message = new Message(['file' => $this->file]);

        static::assertTrue($message->valid());
        static::assertSame($message->getChildren(), $message->current());
        static::assertSame($message->key(), 1);

        $message->next();
        static::assertTrue($message->valid());
        static::assertSame($message->getChildren(), $message->current());
        static::assertSame($message->key(), 2);

        $message->next();
        static::assertFalse($message->valid());

        $message->rewind();
        static::assertTrue($message->valid());
        static::assertSame($message->getChildren(), $message->current());
        static::assertSame($message->key(), 1);
    }

    #[Test]
    public function messageFlagsAreSet(): void
    {
        $origFlags = [
            'foo' => 'bar',
            'baz' => 'bat',
        ];
        $message = new Message(['flags' => $origFlags]);

        $messageFlags = $message->getFlags();
        static::assertTrue($message->hasFlag('bar'), var_export($messageFlags, true));
        static::assertTrue($message->hasFlag('bat'), var_export($messageFlags, true));
        static::assertSame(['bar' => 'bar', 'bat' => 'bat'], $messageFlags);
    }

    #[Test]
    public function getHeaderFieldSingle(): void
    {
        $message = new Message(['file' => $this->file]);
        static::assertSame($message->getHeaderField('subject'), 'multipart');
    }

    #[Test]
    public function getHeaderFieldDefault(): void
    {
        $message = new Message(['file' => $this->file]);
        static::assertSame($message->getHeaderField('content-type'), 'multipart/alternative');
    }

    #[Test]
    public function getHeaderFieldNamed(): void
    {
        $message = new Message(['file' => $this->file]);
        static::assertSame($message->getHeaderField('content-type', 'boundary'), 'crazy-multipart');
    }

    #[Test]
    public function getHeaderFieldMissing(): void
    {
        $message = new Message(['file' => $this->file]);
        static::assertNull($message->getHeaderField('content-type', 'foo'));
    }

    #[Test]
    public function getHeaderFieldInvalid(): void
    {
        $this->expectException(MailException\ExceptionInterface::class);
        $message = new Message(['file' => $this->file]);
        $message->getHeaderField('fake-header-name', 'foo');
    }

    #[Test]
    public function caseInsensitiveMultipart(): void
    {
        $message = new Message(['raw' => "coNTent-TYpe: muLTIpaRT/x-empty\r\n\r\n"]);
        static::assertTrue($message->isMultipart());
    }

    #[Test]
    public function caseInsensitiveField(): void
    {
        $header = 'test; fOO="this is a test"';
        static::assertSame(Mime\Decode::splitHeaderField($header, 'Foo'), 'this is a test');
        static::assertSame(Mime\Decode::splitHeaderField($header, 'bar'), null);
    }

    #[Test]
    public function spaceInFieldName(): void
    {
        $header = 'test; foo =bar; baz      =42';
        static::assertSame(Mime\Decode::splitHeaderField($header, 'foo'), 'bar');
        static::assertEquals(Mime\Decode::splitHeaderField($header, 'baz'), 42);
    }

    /**
     * splitMessage with Headers as input fails to process AddressList with semicolons
     *
     * @see https://github.com/laminas/laminas-mail/pull/93
     */
    #[Test]
    public function headersKeepQuotingOfNamesWithSpecials(): void
    {
        $headerList = [
            'From: "Famous bearings |;" <skf@example.com>',
            'Reply-To: "Famous bearings |:" <skf@example.com>',
        ];

        // create Headers object from array
        Mime\Decode::splitMessage(implode("\r\n", $headerList), $headers1, $body);
        $this->assertInstanceOf(Headers::class, $headers1);
        // create Headers object from Headers object
        Mime\Decode::splitMessage($headers1, $headers2, $body);
        $this->assertInstanceOf(Headers::class, $headers2);

        // test that same problem does not happen with Storage\Message internally
        $message = new Message(['headers' => $headers2, 'content' => (string) $body]);
        $this->assertEquals('"Famous bearings |;" <skf@example.com>', $message->from);
        $this->assertEquals('"Famous bearings |:" <skf@example.com>', $message->replyTo);
    }

    /**
     * @see https://zendframework.com/issues/browse/ZF2-372
     */
    #[Test]
    public function strictParseMessage(): void
    {
        $this->expectException(MailException\RuntimeException::class);

        $raw     = file_get_contents($this->file);
        $raw     = "From foo@example.com  Sun Jan 01 00:00:00 2000\n{$raw}";
        $message = new Message(['raw' => $raw, 'strict' => true]);
    }

    #[Test]
    public function multivaluedToHeader(): void
    {
        $message = new Message(['file' => $this->file2]);
        /** @var To $header */
        $header      = $message->getHeader('to');
        $addressList = $header->getAddressList();
        static::assertSame(2, $addressList->count());
        $address = $addressList->get('bar@example.pl');
        static::assertInstanceOf(AddressInterface::class, $address);
        static::assertSame('nicpoń', $address->getName());
    }

    public static function filesProvider(): array
    {
        $filePath                    = __DIR__ . '/../_files/mail.eml';
        $fileBlankLineOnTop          = __DIR__ . '/../_files/mail_blank_top_line.eml';
        $fileSurroundingSingleQuotes = __DIR__ . '/../_files/mail_surrounding_single_quotes.eml';

        return [
            // Description => [params]
            'resource'                            => [['file' => fopen($filePath, 'r')]],
            'file path'                           => [['file' => $filePath]],
            'raw'                                 => [['raw' => file_get_contents($filePath)]],
            'file with blank line on top'         => [['file' => $fileBlankLineOnTop]],
            'file with surrounding single quotes' => [['file' => $fileSurroundingSingleQuotes]],
        ];
    }
}
