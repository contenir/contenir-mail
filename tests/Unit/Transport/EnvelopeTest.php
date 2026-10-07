<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use ArrayIterator;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Transport\Envelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Envelope::class)]
#[Group('unit')]
final class EnvelopeTest extends TestCase
{
    #[Test]
    public function isEmptyByDefault(): void
    {
        $envelope = new Envelope();

        static::assertSame([null, []], [$envelope->from, $envelope->to]);
    }

    #[Test]
    public function keepsSender(): void
    {
        static::assertSame('bounces@example.com', (new Envelope(from: ' bounces@example.com '))->from);
    }

    #[Test]
    public function readsSingleRecipient(): void
    {
        static::assertSame(['users@example.com'], (new Envelope(to: 'users@example.com'))->to);
    }

    #[Test]
    public function readsRecipientList(): void
    {
        static::assertSame(
            ['users@example.com', 'dev@example.com'],
            (new Envelope(to: new ArrayIterator(['users@example.com', 'dev@example.com'])))->to,
        );
    }

    /**
     * Envelope addresses become SMTP commands, so they are checked when the envelope is made.
     */
    #[DataProvider('unsafeAddressProvider')]
    #[Test]
    public function rejectsUnsafeSender(string $address, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Envelope(from: $address);
    }

    #[DataProvider('unsafeAddressProvider')]
    #[Test]
    public function rejectsUnsafeRecipient(string $address, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Envelope(to: ['users@example.com', $address]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unsafeAddressProvider(): array
    {
        return [
            'CRLF and a command' => ["x@example.com>\r\nRCPT TO:<victim@example.com", 'CRLF injection detected'],
            'not an address'     => ['x@example.com> SIZE=1', 'is not a valid hostname for the email address'],
        ];
    }
}
