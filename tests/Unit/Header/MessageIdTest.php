<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\Exception;
use Contenir\Mail\Header\MessageId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(MessageId::class)]
#[Group('unit')]
final class MessageIdTest extends TestCase
{
    private const string ID = 'CALTvGe4_oYgf9WsYgauv7qXh2-6=KbPLExmJNG7fCs9B=1nOYg@mail.example.com';

    #[Test]
    public function reportsFieldName(): void
    {
        static::assertSame('Message-ID', (new MessageId(self::ID))->getFieldName());
    }

    #[DataProvider('idProvider')]
    #[Test]
    public function holdsIdWithoutAngleBrackets(string $id): void
    {
        static::assertSame(self::ID, (new MessageId($id))->getId());
    }

    #[DataProvider('idProvider')]
    #[Test]
    public function rendersIdInAngleBrackets(string $id): void
    {
        static::assertSame('<' . self::ID . '>', (new MessageId($id))->getFieldValue());
    }

    #[Test]
    public function rendersEncodedFieldValueInAngleBrackets(): void
    {
        static::assertSame('<' . self::ID . '>', (new MessageId(self::ID))->getEncodedFieldValue());
    }

    #[Test]
    public function rendersHeaderLine(): void
    {
        static::assertSame('Message-ID: <' . self::ID . '>', (new MessageId(self::ID))->toString());
    }

    #[DataProvider('headerLineProvider')]
    #[Test]
    public function parsesIdFromString(string $headerLine): void
    {
        static::assertSame('a@example.com', MessageId::fromString($headerLine)->getId());
    }

    #[Test]
    public function generatesRandomIdOnGivenHost(): void
    {
        static::assertMatchesRegularExpression(
            '/^[0-9a-f]{32}@example\.org$/',
            MessageId::generate('example.org')->getId(),
        );
    }

    /**
     * Information disclosure: the default domain does not reveal the sending machine's host name.
     */
    #[Test]
    public function generatesIdOnReservedDomainByDefault(): void
    {
        static::assertMatchesRegularExpression('/^[0-9a-f]{32}@localhost\.invalid$/', MessageId::generate()->getId());
    }

    #[Test]
    public function rejectsDomainHoldingAnAtSign(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ID detected');

        MessageId::generate('a@example.org');
    }

    #[Test]
    public function generatesDifferentIdEachTime(): void
    {
        static::assertNotSame(
            MessageId::generate('example.org')->getId(),
            MessageId::generate('example.org')->getId(),
        );
    }

    #[DataProvider('invalidIdProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsInvalidId(string $id): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ID detected');

        new MessageId($id);
    }

    #[Test]
    public function rejectsHostThatWouldMakeInvalidId(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ID detected');

        MessageId::generate("example.org\r\nBcc: attacker@example.net");
    }

    #[DataProvider('injectedHeaderLineProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsHeaderLineWithLineBreaksInValue(string $headerLine): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header value detected');

        MessageId::fromString($headerLine);
    }

    #[Test]
    public function rejectsEmptyIdFromString(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ID detected');

        MessageId::fromString('Message-ID: <>');
    }

    #[Test]
    public function rejectsHeaderLineOfAnotherHeader(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for Message-ID string');

        MessageId::fromString('Foo: bar');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function idProvider(): array
    {
        return [
            'bare'               => [self::ID],
            'in angle brackets'  => ['<' . self::ID . '>'],
            'surrounding spaces' => [' <' . self::ID . '> '],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function headerLineProvider(): array
    {
        return [
            'in angle brackets'      => ['Message-ID: <a@example.com>'],
            'bare'                   => ['Message-ID: a@example.com'],
            'lower-case header name' => ['message-id: <a@example.com>'],
            'upper-case header name' => ['MESSAGE-ID: <a@example.com>'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIdProvider(): array
    {
        return [
            'newline'             => ["foo\nbar"],
            'cr-lf'               => ["bar\r\nfoo"],
            'cr-lf twice'         => ["bar\r\n\r\n baz"],
            'multiline'           => ["baz\r\nbar\r\nbau"],
            'folding'             => ["bar\r\n baz"],
            'trailing cr-lf'      => ["a@example.com\r\n"],
            'space'               => ['a b@example.com'],
            'inner angle bracket' => ['a<b@example.com'],
            'non-ASCII'           => ['á@example.com'],
            'empty'               => [''],
            'only angle brackets' => ['<>'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedHeaderLineProvider(): array
    {
        return [
            'newline'     => ["Message-ID: foo\nbar"],
            'cr-lf'       => ["Message-ID: bar\r\nfoo"],
            'cr-lf twice' => ["Message-ID: bar\r\n\r\n baz"],
            'multiline'   => ["Message-ID: baz\r\nbar\r\nbau"],
        ];
    }

    #[Test]
    public function acceptsIdOfMaximumLength(): void
    {
        $id = str_repeat('a', times: 971) . '@example.com';

        static::assertSame("<{$id}>", (new MessageId($id))->getFieldValue());
    }

    /**
     * An ID cannot be encoded or folded, so one too long for a line is refused.
     */
    #[Test]
    public function rejectsIdTooLongForLineLimit(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('An ID may be at most 983 characters, so that its header line fits in 998');

        new MessageId(str_repeat('a', times: 972) . '@example.com');
    }
}
