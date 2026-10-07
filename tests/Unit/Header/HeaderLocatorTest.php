<?php

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header;
use Contenir\Mail\Header\HeaderLocator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HeaderLocatorTest extends TestCase
{
    private HeaderLocator $headerLocator;

    public function setUp(): void
    {
        $this->headerLocator = new Header\HeaderLocator();
    }

    public static function provideHeaderNames(): array
    {
        return [
            'with existing name'     => ['to', Header\To::class],
            'with non-existent name' => ['foo', null],
            'with default value'     => ['foo', Header\GenericHeader::class, Header\GenericHeader::class],
        ];
    }

    /**
     * @param null|class-string<Header\HeaderInterface> $expected
     * @param null|class-string<Header\HeaderInterface> $default
     */
    #[Test]
    #[DataProvider('provideHeaderNames')]
    public function headerIsProperlyLoaded(string $name, ?string $expected, ?string $default = null): void
    {
        static::assertEquals($expected, $this->headerLocator->get($name, $default));
    }

    #[Test]
    public function headerExistenceIsProperlyChecked(): void
    {
        static::assertTrue($this->headerLocator->has('to'));
        static::assertTrue($this->headerLocator->has('To'));
        static::assertTrue($this->headerLocator->has('Reply_to'));
        static::assertTrue($this->headerLocator->has('SUBJECT'));
        static::assertFalse($this->headerLocator->has('foo'));
        static::assertFalse($this->headerLocator->has('bar'));
    }

    #[Test]
    public function headerCanBeAdded(): void
    {
        static::assertFalse($this->headerLocator->has('foo'));
        $this->headerLocator->add('foo', Header\GenericHeader::class);
        static::assertTrue($this->headerLocator->has('foo'));
    }

    #[Test]
    public function headerCanBeRemoved(): void
    {
        static::assertTrue($this->headerLocator->has('to'));
        $this->headerLocator->remove('to');
        static::assertFalse($this->headerLocator->has('to'));
    }

    public static function expectedHeaders(): array
    {
        return [
            'bcc'          => ['bcc', Header\Bcc::class],
            'cc'           => ['cc', Header\Cc::class],
            'contenttype'  => ['contenttype', Header\ContentType::class],
            'content_type' => ['content_type', Header\ContentType::class],
            'content-type' => ['content-type', Header\ContentType::class],
            'date'         => ['date', Header\Date::class],
            'from'         => ['from', Header\From::class],
            'mimeversion'  => ['mimeversion', Header\MimeVersion::class],
            'mime_version' => ['mime_version', Header\MimeVersion::class],
            'mime-version' => ['mime-version', Header\MimeVersion::class],
            'received'     => ['received', Header\Received::class],
            'replyto'      => ['replyto', Header\ReplyTo::class],
            'reply_to'     => ['reply_to', Header\ReplyTo::class],
            'reply-to'     => ['reply-to', Header\ReplyTo::class],
            'sender'       => ['sender', Header\Sender::class],
            'subject'      => ['subject', Header\Subject::class],
            'to'           => ['to', Header\To::class],
        ];
    }

    /**
     * @param string $name
     * @param Header\HeaderInterface $class
     */
    #[Test]
    #[DataProvider('expectedHeaders')]
    public function defaultHeadersMapResolvesProperHeader($name, $class): void
    {
        static::assertEquals($class, $this->headerLocator->get($name));
    }
}
