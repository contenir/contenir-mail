<?php

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Message;
use Contenir\Mail\Transport\InMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Contenir\Mail\Transport\InMemory::class)]
class InMemoryTest extends TestCase
{
    public function getMessage(): Message
    {
        $message = new Message();
        $message->addTo('test@example.com', 'Example Test')
            ->addCc('matthew@example.com')
            ->addBcc('list@example.com', 'Example List')
            ->addFrom([
                'test@example.com',
                'matthew@example.com' => 'Matthew',
            ])
            ->setSender('ralph@example.com', 'Ralph Schindler')
            ->setSubject('Testing Contenir\Mail\Transport\Sendmail')
            ->setBody('This is only a test.');
        $message->getHeaders()
            ->addHeaders([
                'X-Foo-Bar' => 'Matthew',
            ]);
        return $message;
    }

    #[Test]
    public function receivesMailArtifacts(): void
    {
        $message   = $this->getMessage();
        $transport = new InMemory();

        $transport->send($message);

        static::assertSame($message, $transport->getLastMessage());
    }

    #[Test]
    public function nullMessage(): void
    {
        $transport = new InMemory();
        static::assertNull($transport->getLastMessage());
    }
}
