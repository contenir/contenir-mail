<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Header\AbstractAddressList;
use Contenir\Mail\Header\Date;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Message::class)]
#[CoversClass(Headers::class)]
#[CoversClass(AbstractAddressList::class)]
#[Group('unit')]
final class MessageCloneTest extends TestCase
{
    #[Test]
    public function addingRecipientToCloneLeavesOriginalUnchanged(): void
    {
        $original = $this->makeMessage();
        $copy     = clone $original;

        $copy->addTo('second@example.com');

        static::assertSame(['first@example.com'], $this->recipients($original));
    }

    #[Test]
    public function changingSubjectOfCloneLeavesOriginalUnchanged(): void
    {
        $original = $this->makeMessage();
        $copy     = clone $original;

        $copy->setSubject('Changed');

        static::assertSame('Original', $original->getSubject());
    }

    #[Test]
    public function addingHeaderToCloneLeavesOriginalUnchanged(): void
    {
        $original = $this->makeMessage();
        $copy     = clone $original;

        $copy->addHeader(new GenericHeader('X-Copy', 'yes'));

        static::assertFalse($original->getHeaders()->has('X-Copy'));
    }

    #[Test]
    public function removingHeaderFromCloneLeavesOriginalUnchanged(): void
    {
        $original = $this->makeMessage();
        $copy     = clone $original;

        $copy->removeHeader('Subject');

        static::assertSame('Original', $original->getSubject());
    }

    #[Test]
    public function replacingRecipientsOfCloneLeavesOriginalUnchanged(): void
    {
        $original = $this->makeMessage();
        $copy     = clone $original;

        $copy->setTo('other@example.com');

        static::assertSame(['first@example.com'], $this->recipients($original));
    }

    #[Test]
    public function changingBodyOfCloneLeavesOriginalUnchanged(): void
    {
        $original = $this->makeMessage();
        $copy     = clone $original;

        $copy->setBody('Changed');

        static::assertSame('Original body', $original->getBodyText());
    }

    #[Test]
    public function cloneKeepsHeadersOfOriginal(): void
    {
        $original = $this->makeMessage();

        static::assertSame($original->getHeaders()->toString(), (clone $original)->getHeaders()->toString());
    }

    #[Test]
    public function clonesMessageWithoutHeaders(): void
    {
        $copy = clone new Message(new Headers());

        $copy->setSubject('Only on the copy');

        static::assertSame('Only on the copy', $copy->getSubject());
    }

    private function makeMessage(): Message
    {
        $message = new Message(new Headers(new Date(new DateTimeImmutable('2024-01-01T00:00:00Z'))));
        $message->addTo('first@example.com');
        $message->setSubject('Original');
        $message->setBody('Original body');

        return $message;
    }

    /**
     * @return list<string>
     */
    private function recipients(Message $message): array
    {
        $emails = [];
        foreach ($message->getTo() as $address) {
            $emails[] = $address->getEmail();
        }

        return $emails;
    }
}
