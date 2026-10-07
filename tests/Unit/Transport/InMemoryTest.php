<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Header\GenericHeader;
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
    private function makeMessage(): Message
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
            ->setBody('This is only a test.')
            ->addHeader(new GenericHeader('X-Foo-Bar', 'Matthew'));

        return $message;
    }

    #[Test]
    public function receivesMailArtifacts(): void
    {
        $message   = $this->makeMessage();
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
