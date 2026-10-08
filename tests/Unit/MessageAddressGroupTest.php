<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Address;
use Contenir\Mail\AddressGroup;
use Contenir\Mail\AddressList;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Message::class)]
#[Group('unit')]
final class MessageAddressGroupTest extends TestCase
{
    /**
     * @param callable(Message): Message $build
     */
    #[Test]
    #[DataProvider('builderProvider')]
    public function writesGroupGivenToBuilder(callable $build, string $expected): void
    {
        static::assertSame($expected, $build(new Message(new Headers()))->getHeaders()->toString());
    }

    /**
     * @return array<string, array{callable(Message): Message, string}>
     */
    public static function builderProvider(): array
    {
        $group = new AddressGroup('undisclosed-recipients');

        return [
            'setTo'      => [static fn(Message $m): Message => $m->setTo($group), "To: undisclosed-recipients:;\r\n"],
            'addTo'      => [static fn(Message $m): Message => $m->addTo($group), "To: undisclosed-recipients:;\r\n"],
            'setCc'      => [static fn(Message $m): Message => $m->setCc($group), "Cc: undisclosed-recipients:;\r\n"],
            'addCc'      => [static fn(Message $m): Message => $m->addCc($group), "Cc: undisclosed-recipients:;\r\n"],
            'setBcc'     => [static fn(Message $m): Message => $m->setBcc($group), "Bcc: undisclosed-recipients:;\r\n"],
            'addBcc'     => [static fn(Message $m): Message => $m->addBcc($group), "Bcc: undisclosed-recipients:;\r\n"],
            'setReplyTo' => [
                static fn(Message $m): Message => $m->setReplyTo($group),
                "Reply-To: undisclosed-recipients:;\r\n",
            ],
            'addReplyTo' => [
                static fn(Message $m): Message => $m->addReplyTo($group),
                "Reply-To: undisclosed-recipients:;\r\n",
            ],
            'setFrom'    => [
                static fn(Message $m): Message => $m->setFrom(
                    new AddressGroup('Team', new AddressList(new Address('jo@example.org'))),
                ),
                "From: Team: jo@example.org;\r\n",
            ],
            'addFrom'    => [
                static fn(Message $m): Message => $m->addFrom('jo@example.org')->addFrom(new AddressGroup('Team')),
                "From: jo@example.org,\r\n Team:;\r\n",
            ],
        ];
    }

    #[Test]
    public function keepsGroupWhenAddressesAreAdded(): void
    {
        $message = (new Message(new Headers()))->addTo(
            new AddressGroup('Team', new AddressList(new Address('a@example.org'))),
        )
            ->addTo('b@example.org');

        static::assertSame('Team: a@example.org;, b@example.org', $message->getHeaders()->get('To')?->getFieldValue());
    }

    #[Test]
    public function namesGroupMembersAmongTheRecipients(): void
    {
        $message = (new Message(new Headers()))->addTo(
            new AddressGroup('Team', new AddressList(new Address('a@example.org'), new Address('b@example.org'))),
        );

        static::assertSame(2, $message->getTo()->count());
    }

    #[Test]
    public function refusesDisplayNameWithGroup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A display name can only be given with a single e-mail address');

        (new Message())->addTo(new AddressGroup('Team'), 'Name');
    }
}
