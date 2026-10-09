<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Address;
use Contenir\Mail\AddressGroup;
use Contenir\Mail\AddressList;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Header\AbstractAddressList;
use Contenir\Mail\Header\Bcc;
use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Adding recipients one at a time, as a mailing loop does.
 */
#[CoversClass(Message::class)]
#[CoversClass(Headers::class)]
#[CoversClass(AbstractAddressList::class)]
#[CoversClass(AddressList::class)]
#[Group('unit')]
final class MessageRecipientAppendTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function addMethodProvider(): array
    {
        return [
            'From'     => ['addFrom', 'getFrom'],
            'To'       => ['addTo', 'getTo'],
            'Cc'       => ['addCc', 'getCc'],
            'Bcc'      => ['addBcc', 'getBcc'],
            'Reply-To' => ['addReplyTo', 'getReplyTo'],
        ];
    }

    #[DataProvider('addMethodProvider')]
    #[Test]
    public function addsEachRecipientOnceInTheOrderGiven(string $add, string $get): void
    {
        $message = new Message();
        foreach ([
            'jo@example.org',
            'sam@example.org',
            'JO@Example.org',
            'ann@example.org',
            'Sam@example.org',
        ] as $email) {
            $message->{$add}($email);
        }

        static::assertSame(
            ['jo@example.org', 'sam@example.org', 'ann@example.org'],
            array_map(static fn(Address $address): string => $address->getEmail(), $message->{$get}()->toArray()),
        );
    }

    #[Test]
    public function keepsALenientRecipientAddedOneAtATime(): void
    {
        $lenient = new Address('jo..bloggs@example.org', strict: false);
        $message = (new Message())->addBcc('sam@example.org')
            ->addBcc($lenient);

        static::assertSame($lenient, $message->getBcc()->get('jo..bloggs@example.org'));
    }

    #[Test]
    public function addsARecipientAlsoInAGroupOutsideIt(): void
    {
        $message = (new Message())->addTo(new AddressGroup('Team', new AddressList(new Address('jo@example.org'))))
            ->addTo('jo@example.org');

        static::assertSame(
            'Team: jo@example.org;, jo@example.org',
            $message->getHeaders()->get('To')?->getFieldValue(),
        );
    }

    #[Test]
    public function stillRefusesASecondBccHeaderAfterAddingRecipients(): void
    {
        $message = (new Message())->addBcc('jo@example.org')
            ->addBcc('sam@example.org');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A message may have only one Bcc header; use setHeader() to replace it');
        $message->addHeader(new Bcc(new Address('ann@example.org')));
    }
}
