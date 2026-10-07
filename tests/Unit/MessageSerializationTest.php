<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Tests\Unit\TestAsset\FixedClock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function serialize;
use function unserialize;

#[CoversClass(Message::class)]
#[CoversClass(Headers::class)]
#[CoversClass(Part::class)]
#[Group('unit')]
final class MessageSerializationTest extends TestCase
{
    private static function message(): Message
    {
        return (new Message(clock: new FixedClock(new DateTimeImmutable('2026-10-08 09:00:00 +1100'))))->setFrom(
            'orders@example.com',
        )
            ->addTo('jo@example.org')
            ->setSubject('Your order')
            ->setText("Thanks for your order.\nIt ships tomorrow.")
            ->setHtml('<p>Thanks for your order.</p>')
            ->attach(Attachment::fromString('PDF', filename: 'invoice.pdf', type: 'application/pdf'));
    }

    #[Test]
    public function writesTheSameMessageAfterSerialization(): void
    {
        $message = self::message();

        static::assertSame($message->toString(), unserialize(serialize($message))->toString());
    }

    #[Test]
    public function writesAParsedMessageAsReadAfterSerialization(): void
    {
        $raw = "Subject:   kept  as  read\r\nFrom: jo@example.org\r\n\r\nBody";

        static::assertSame($raw, unserialize(serialize(Message::fromString($raw)))->toString());
    }
}
