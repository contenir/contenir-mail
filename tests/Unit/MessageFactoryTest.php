<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Exception;
use Contenir\Mail\Message;
use Contenir\Mail\MessageFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

#[CoversClass(MessageFactory::class)]
class MessageFactoryTest extends TestCase
{
    #[Test]
    public function constructMessageWithOptions(): void
    {
        $options = [
            'encoding' => 'UTF-8',
            'from'     => 'matthew@example.com',
            'to'       => 'test@example.com',
            'cc'       => 'list@example.com',
            'bcc'      => 'test@example.com',
            'reply-to' => 'matthew@example.com',
            'sender'   => 'matthew@example.com',
            'subject'  => 'subject',
            'body'     => 'body',
        ];

        $message = MessageFactory::getInstance($options);

        static::assertInstanceOf(Message::class, $message);
        static::assertSame('UTF-8', $message->getEncoding());
        static::assertSame('subject', $message->getSubject());
        static::assertSame('body', $message->getBody());
        static::assertInstanceOf(Address::class, $message->getSender());
        static::assertSame($options['sender'], $message->getSender()->getEmail());

        $getMethods = [
            'from'     => 'getFrom',
            'to'       => 'getTo',
            'cc'       => 'getCc',
            'bcc'      => 'getBcc',
            'reply-to' => 'getReplyTo',
        ];

        foreach ($getMethods as $key => $method) {
            $value = $message->{$method}();
            static::assertInstanceOf(AddressList::class, $value);
            static::assertSame(1, count($value));
            static::assertTrue($value->has($options[$key]));
        }
    }

    #[Test]
    public function canCreateMessageWithMultipleRecipientsViaArrayValue(): void
    {
        $options = [
            'from' => ['matthew@example.com' => 'Matthew'],
            'to'   => [
                'test@example.com',
                'list@example.com',
            ],
        ];

        $message = MessageFactory::getInstance($options);

        $from = $message->getFrom();
        static::assertInstanceOf(AddressList::class, $from);
        static::assertSame(1, count($from));
        static::assertTrue($from->has('matthew@example.com'));
        static::assertSame('Matthew', $from->get('matthew@example.com')->getName());

        $to = $message->getTo();
        static::assertInstanceOf(AddressList::class, $to);
        static::assertSame(2, count($to));
        static::assertTrue($to->has('test@example.com'));
        static::assertTrue($to->has('list@example.com'));
    }

    #[Test]
    public function ignoresUnreconizedOptions(): void
    {
        $options = [
            'foo' => 'bar',
        ];
        $mail = MessageFactory::getInstance($options);
        static::assertInstanceOf(Message::class, $mail);
    }

    #[Test]
    public function emptyOption(): void
    {
        $mail = MessageFactory::getInstance();
        static::assertInstanceOf(Message::class, $mail);
    }

    public static function invalidMessageOptions(): array
    {
        return [
            'null'         => [null],
            'bool'         => [true],
            'int'          => [1],
            'float'        => [1.1],
            'string'       => ['not-an-array'],
            'plain-object' => [
                (object) [
                    'from' => 'matthew@example.com',
                    'to'   => 'foo@example.com',
                ],
            ],
        ];
    }

    #[Test]
    #[DataProvider('invalidMessageOptions')]
    public function exceptionForOptionsNotArrayOrTraversable(mixed $options): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        MessageFactory::getInstance($options);
    }
}
