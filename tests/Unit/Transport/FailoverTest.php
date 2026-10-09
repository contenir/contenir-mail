<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use Contenir\Mail\Tests\TestAsset\FailingTransport;
use Contenir\Mail\Transport\Exception\InvalidArgumentException;
use Contenir\Mail\Transport\Exception\RuntimeException;
use Contenir\Mail\Transport\Failover;
use Contenir\Mail\Transport\InMemory;
use Contenir\Mail\Transport\TransportInterface;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sprintf;

#[CoversClass(Failover::class)]
#[Group('unit')]
final class FailoverTest extends TestCase
{
    private static function message(): Message
    {
        return (new Message(new Headers()))->setFrom('jo@example.org')
            ->addTo('sam@example.org');
    }

    #[Test]
    public function sendsThroughTheFirstTransport(): void
    {
        $first   = new InMemory();
        $message = self::message();

        (new Failover($first, new FailingTransport()))->send($message);

        static::assertSame($message, $first->getLastMessage());
    }

    #[Test]
    public function triesTheNextTransportWhenOneFails(): void
    {
        $second  = new InMemory();
        $message = self::message();

        (new Failover(new FailingTransport(), $second))->send($message);

        static::assertSame($message, $second->getLastMessage());
    }

    #[Test]
    public function stopsAtTheFirstTransportThatSucceeds(): void
    {
        $third = new FailingTransport();

        (new Failover(new FailingTransport(), new InMemory(), $third))->send(self::message());

        static::assertSame(0, $third->attempts);
    }

    #[Test]
    public function reportsEveryFailureWhenAllFail(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'Every transport failed: 1. %1$s: connection refused; 2. %1$s: authentication failed',
            FailingTransport::class,
        ));

        (new Failover(new FailingTransport('connection refused'), new FailingTransport('authentication failed')))->send(
            self::message(),
        );
    }

    #[Test]
    public function keepsTheLastFailureAsThePreviousException(): void
    {
        $previous = null;
        try {
            (new Failover(new FailingTransport('first'), new FailingTransport('second')))->send(self::message());
        } catch (RuntimeException $exception) {
            $previous = $exception->getPrevious()?->getMessage();
        }

        static::assertSame('second', $previous);
    }

    #[Test]
    public function letsErrorsFromOutsideThePackageThrough(): void
    {
        $transport = new class implements TransportInterface {
            #[Override]
            public function send(Message $message): void
            {
                throw new LogicException('a bug in the transport');
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('a bug in the transport');

        (new Failover($transport, new InMemory()))->send(self::message());
    }

    #[Test]
    public function listsItsTransportsInOrder(): void
    {
        $first  = new InMemory();
        $second = new InMemory();

        static::assertSame([$first, $second], (new Failover($first, $second))->getTransports());
    }

    #[Test]
    public function refusesAnEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A failover transport needs at least one transport');

        new Failover();
    }
}
