<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header;
use Contenir\Mail\Header\HeaderLocator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeaderLocator::class)]
#[Group('unit')]
final class HeaderLocatorTest extends TestCase
{
    /**
     * @param class-string<Header\HeaderInterface> $class
     */
    #[DataProvider('defaultHeaderProvider')]
    #[Test]
    public function resolvesBuiltInHeaderClass(string $name, string $class): void
    {
        static::assertSame($class, (new HeaderLocator())->get($name));
    }

    #[DataProvider('defaultHeaderProvider')]
    #[Test]
    public function knowsBuiltInHeader(string $name): void
    {
        static::assertTrue((new HeaderLocator())->has($name));
    }

    #[DataProvider('unknownNameProvider')]
    #[Test]
    public function returnsNullForUnknownHeader(string $name): void
    {
        static::assertNull((new HeaderLocator())->get($name));
    }

    #[DataProvider('unknownNameProvider')]
    #[Test]
    public function doesNotKnowUnknownHeader(string $name): void
    {
        static::assertFalse((new HeaderLocator())->has($name));
    }

    #[Test]
    public function constructorClassesOverrideDefaults(): void
    {
        $locator = new HeaderLocator(['To' => Header\GenericHeader::class]);

        static::assertSame(Header\GenericHeader::class, $locator->get('to'));
    }

    #[Test]
    public function constructorClassesAddNewHeaders(): void
    {
        $locator = new HeaderLocator(['X-Custom' => Header\GenericHeader::class]);

        static::assertSame(Header\GenericHeader::class, $locator->get('x_custom'));
    }

    #[Test]
    public function constructorClassesKeepRemainingDefaults(): void
    {
        $locator = new HeaderLocator(['To' => Header\GenericHeader::class]);

        static::assertSame(Header\Subject::class, $locator->get('subject'));
    }

    #[Test]
    public function withAddsHeaderToNewLocator(): void
    {
        $locator = (new HeaderLocator())->with('X-Custom', Header\GenericHeader::class);

        static::assertSame(Header\GenericHeader::class, $locator->get('x.custom'));
    }

    #[Test]
    public function withOverridesExistingHeader(): void
    {
        $locator = (new HeaderLocator())->with('Content_Type', Header\GenericHeader::class);

        static::assertSame(Header\GenericHeader::class, $locator->get('Content-Type'));
    }

    #[Test]
    public function withLeavesOriginalLocatorUnchanged(): void
    {
        $locator = new HeaderLocator();
        static::assertNotSame($locator, $locator->with('X-Custom', Header\GenericHeader::class));

        static::assertFalse($locator->has('X-Custom'));
    }

    #[Test]
    public function withKeepsEarlierOverrides(): void
    {
        $locator = (new HeaderLocator(['To' => Header\GenericHeader::class]))->with('X-Custom', Header\Subject::class);

        static::assertSame(Header\GenericHeader::class, $locator->get('to'));
    }

    /**
     * @return array<string, array{string, class-string<Header\HeaderInterface>}>
     */
    public static function defaultHeaderProvider(): array
    {
        return [
            'bcc'                       => ['bcc', Header\Bcc::class],
            'cc'                        => ['cc', Header\Cc::class],
            'content-disposition'       => ['content-disposition', Header\ContentDisposition::class],
            'content-transfer-encoding' => ['content-transfer-encoding', Header\ContentTransferEncoding::class],
            'contenttype'               => ['contenttype', Header\ContentType::class],
            'content_type'              => ['content_type', Header\ContentType::class],
            'content-type'              => ['content-type', Header\ContentType::class],
            'content type'              => ['content type', Header\ContentType::class],
            'content.type'              => ['content.type', Header\ContentType::class],
            'date'                      => ['date', Header\Date::class],
            'from'                      => ['from', Header\From::class],
            'in-reply-to'               => ['in-reply-to', Header\InReplyTo::class],
            'message-id'                => ['message-id', Header\MessageId::class],
            'mimeversion'               => ['mimeversion', Header\MimeVersion::class],
            'mime_version'              => ['mime_version', Header\MimeVersion::class],
            'mime-version'              => ['mime-version', Header\MimeVersion::class],
            'received'                  => ['received', Header\Received::class],
            'references'                => ['references', Header\References::class],
            'replyto'                   => ['replyto', Header\ReplyTo::class],
            'reply_to'                  => ['reply_to', Header\ReplyTo::class],
            'reply-to'                  => ['reply-to', Header\ReplyTo::class],
            'Reply_to'                  => ['Reply_to', Header\ReplyTo::class],
            'sender'                    => ['sender', Header\Sender::class],
            'subject'                   => ['subject', Header\Subject::class],
            'SUBJECT'                   => ['SUBJECT', Header\Subject::class],
            'to'                        => ['to', Header\To::class],
            'To'                        => ['To', Header\To::class],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownNameProvider(): array
    {
        return [
            'foo'                => ['foo'],
            'bar'                => ['bar'],
            'x-mailer'           => ['x-mailer'],
            'prefix of a header' => ['content'],
        ];
    }
}
