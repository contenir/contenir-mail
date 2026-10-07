<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Message;
use Contenir\Mail\Transport\InMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemory::class)]
#[Group('unit')]
final class InMemoryTest extends TestCase
{
    #[Test]
    public function keepsLastMessageSent(): void
    {
        $transport = new InMemory();
        $transport->send((new Message())->setSubject('first'));
        $message = (new Message())->setSubject('second');

        $transport->send($message);

        static::assertSame($message, $transport->getLastMessage());
    }

    #[Test]
    public function hasNoMessageBeforeSending(): void
    {
        static::assertNull((new InMemory())->getLastMessage());
    }
}
