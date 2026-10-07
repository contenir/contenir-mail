<?php

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Message;
use Contenir\Mail\Transport\InMemory;
use PHPUnit\Framework\TestCase;

/**
 * @group      Contenir_Mail
 * @covers Contenir\Mail\Transport\InMemory<extended>
 */
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
        $message->getHeaders()->addHeaders([
            'X-Foo-Bar' => 'Matthew',
        ]);
        return $message;
    }

    public function testReceivesMailArtifacts(): void
    {
        $message   = $this->getMessage();
        $transport = new InMemory();

        $transport->send($message);

        $this->assertSame($message, $transport->getLastMessage());
    }

    public function testNullMessage(): void
    {
        $transport = new InMemory();
        $this->assertNull($transport->getLastMessage());
    }
}
