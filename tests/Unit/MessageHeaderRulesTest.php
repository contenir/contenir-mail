<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\AddressList;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Header\HeaderInterface;
use Contenir\Mail\Header\Subject;
use Contenir\Mail\Header\To;
use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function preg_quote;

/**
 * The header rules RFC 5322 section 3.6 sets for a message: one of each
 * unique header, a Sender when there are several authors, and a Message-ID.
 */
#[CoversClass(Message::class)]
#[Group('unit')]
final class MessageHeaderRulesTest extends TestCase
{
    private static function messageId(Message $message): string
    {
        return (string) $message->getHeaders()->get('Message-ID')?->getFieldValue();
    }

    #[Test]
    #[DataProvider('messageIdDomainProvider')]
    public function generatesMessageIdOnTheSendersDomain(Message $message, string $domain): void
    {
        static::assertSame(1, preg_match(
            '/^<[0-9a-f]{32}@' . preg_quote($domain, delimiter: '/') . '>$/D',
            self::messageId($message),
        ));
    }

    /**
     * @return array<string, array{Message, string}>
     */
    public static function messageIdDomainProvider(): array
    {
        return [
            'From'                                      => [(new Message())->setFrom('jo@example.org'), 'example.org'],
            'Sender before From'                        => [
                (new Message())->setFrom('jo@example.org')
                    ->setSender('bot@mail.example.com'),
                'mail.example.com',
            ],
            'no author'                                 => [new Message(), 'localhost.invalid'],
            'internationalised host'                    => [
                (new Message())->setFrom('jo@bücher.example'),
                'localhost.invalid',
            ],
            'internationalised label after a plain one' => [
                (new Message())->setFrom('jo@mail.bücher.example'),
                'localhost.invalid',
            ],
            'single-label host'                         => [
                (new Message())->setFrom('jo@localhost'),
                'localhost.invalid',
            ],
        ];
    }

    #[Test]
    public function keepsTheSameMessageIdOnEveryRead(): void
    {
        $message = (new Message())->setFrom('jo@example.org');

        static::assertSame(self::messageId($message), self::messageId($message));
    }

    #[Test]
    public function keepsMessageIdGivenByTheCaller(): void
    {
        $message = (new Message())->setHeader(new GenericHeader('Message-ID', '<given@example.org>'));

        static::assertSame('<given@example.org>', self::messageId($message));
    }

    #[Test]
    public function addsNoMessageIdToParsedMessage(): void
    {
        static::assertFalse(Message::fromString("From: jo@example.org\r\n\r\nBody")->getHeaders()->has('Message-ID'));
    }

    #[Test]
    public function addsNoMessageIdToHeadersGivenWhole(): void
    {
        static::assertFalse(
            (new Message())->setHeaders(new Headers())
                ->getHeaders()
                ->has('Message-ID'),
        );
    }

    #[Test]
    public function addsNoMessageIdOnceRemoved(): void
    {
        $message = new Message();
        $message->getHeaders();

        static::assertFalse($message->removeHeader('Message-ID')->getHeaders()->has('Message-ID'));
    }

    #[Test]
    public function keepsGeneratingAfterRemovingAnotherHeader(): void
    {
        static::assertTrue(
            (new Message())->removeHeader('Subject')
                ->getHeaders()
                ->has('Message-ID'),
        );
    }

    #[Test]
    #[DataProvider('uniqueHeaderProvider')]
    public function refusesSecondUniqueHeader(HeaderInterface $first, HeaderInterface $second, string $name): void
    {
        $message = (new Message(new Headers()))->addHeader($first);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A message may have only one {$name} header; use setHeader() to replace it");

        $message->addHeader($second);
    }

    /**
     * @return array<string, array{HeaderInterface, HeaderInterface, string}>
     */
    public static function uniqueHeaderProvider(): array
    {
        return [
            'Subject'                => [new Subject('One'), new Subject('Two'), 'Subject'],
            'To'                     => [
                new To(AddressList::fromIterable(['a@example.org'])),
                new To(AddressList::fromIterable(['b@example.org'])),
                'To',
            ],
            'Message-ID in any case' => [
                new GenericHeader('Message-ID', '<a@x.example>'),
                new GenericHeader('message-id', '<b@x.example>'),
                'Message-Id',
            ],
            'References'             => [
                new GenericHeader('References', '<a@x.example>'),
                new GenericHeader('References', '<b@x.example>'),
                'References',
            ],
        ];
    }

    #[Test]
    public function addsRepeatableHeaderAgain(): void
    {
        $message = (new Message(new Headers()))->addHeader(new GenericHeader('Received', 'from a'))
            ->addHeader(new GenericHeader('Received', 'from b'));

        static::assertCount(2, $message->getHeaders()->all('Received'));
    }

    #[Test]
    public function addsUniqueHeaderThatIsNotYetThere(): void
    {
        static::assertSame(
            'Hi',
            (new Message(new Headers()))->addHeader(new Subject('Hi'))
                ->getSubject(),
        );
    }

    #[Test]
    public function namesFirstAuthorAsSenderWhenThereAreSeveral(): void
    {
        $message = (new Message(new Headers()))->setFrom(['jo@example.org', 'sam@example.org']);

        static::assertSame('jo@example.org', $message->getHeaders()->get('Sender')?->getFieldValue());
    }

    #[Test]
    public function addsNoSenderForOneAuthor(): void
    {
        static::assertFalse(
            (new Message(new Headers()))->setFrom('jo@example.org')
                ->getHeaders()
                ->has('Sender'),
        );
    }

    #[Test]
    public function keepsSenderGivenByTheCaller(): void
    {
        $message = (new Message(new Headers()))->setFrom(['jo@example.org', 'sam@example.org'])
            ->setSender('bot@example.org');

        static::assertSame('bot@example.org', $message->getHeaders()->get('Sender')?->getFieldValue());
    }

    #[Test]
    public function addsNoStoredSenderOnRead(): void
    {
        $message = (new Message(new Headers()))->setFrom(['jo@example.org', 'sam@example.org']);
        $message->getHeaders();

        static::assertNull($message->getSender());
    }
}
