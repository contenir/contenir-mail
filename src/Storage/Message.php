<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\AddressList;
use Contenir\Mail\Header\AbstractAddressList;
use Contenir\Mail\Header\Date;
use Contenir\Mail\Header\MessageId;
use Contenir\Mail\Headers;
use Contenir\Mail\Mime\PartInterface;
use DateTimeImmutable;
use IteratorAggregate;
use Override;

use function in_array;

/**
 * A message read from a mailbox: its top-level part, and the flags the mailbox keeps for it.
 *
 * The message is its own top-level MIME part, so it can be attached to a
 * new message (as message/rfc822, with toString()) or its parts forwarded
 * without parsing it again. Header values are as the sender wrote them;
 * pass display names through Header\SafeText before showing them.
 *
 * @implements IteratorAggregate<int, Part>
 *
 * @mago-expect lint:too-many-methods PartInterface, the laminas-mail part accessors, and accessors for the common headers and flags.
 *
 * @api
 */
final class Message implements PartInterface, IteratorAggregate
{
    /** @var list<Flag|string> */
    private readonly array $flags;

    /**
     * @param iterable<Flag|string> $flags
     *
     * @internal Storage classes and fromString() build messages.
     */
    public function __construct(
        private readonly Part $part,
        iterable $flags = [],
    ) {
        $normalised = [];
        foreach ($flags as $flag) {
            $flag = Flag::normalise($flag);
            if (! in_array($flag, $normalised, strict: true)) {
                $normalised[] = $flag;
            }
        }

        $this->flags = $normalised;
    }

    /**
     * Read a message from its text, as stored or as received.
     *
     * @param iterable<Flag|string> $flags Flags as cases, or as IMAP names such as "\Seen".
     * @throws Exception\RuntimeException When the header block is too large or malformed.
     */
    public static function fromString(string $raw, iterable $flags = []): self
    {
        return new self(Part::fromString($raw), $flags);
    }

    /**
     * A child part by number, the first being 1, as in laminas-mail.
     *
     * @throws Exception\OutOfBoundsException When there is no such part.
     * @throws Exception\RuntimeException When the parts cannot be read.
     */
    public function getPart(int $number): Part
    {
        return $this->part->getPart($number);
    }

    #[Override]
    public function getHeaders(): Headers
    {
        return $this->part->getHeaders();
    }

    /**
     * The decoded Subject, or null when there is none.
     */
    public function getSubject(): ?string
    {
        return $this->getHeaders()->get('Subject')?->getFieldValue();
    }

    public function getFrom(): AddressList
    {
        return $this->addressList('From');
    }

    public function getTo(): AddressList
    {
        return $this->addressList('To');
    }

    public function getCc(): AddressList
    {
        return $this->addressList('Cc');
    }

    public function getReplyTo(): AddressList
    {
        return $this->addressList('Reply-To');
    }

    /**
     * The Date header, or null when there is none or it is not a valid date.
     */
    public function getDate(): ?DateTimeImmutable
    {
        $date = $this->getHeaders()->get('Date');

        return $date instanceof Date ? $date->getDate() : null;
    }

    /**
     * The Message-ID without angle brackets, or null when there is none or it is not valid.
     */
    public function getMessageId(): ?string
    {
        $id = $this->getHeaders()->get('Message-ID');

        return $id instanceof MessageId ? $id->getId() : null;
    }

    /**
     * @return list<Flag|string> Cases for the common flags, strings for keywords.
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    /**
     * @param Flag|string $flag A case, or an IMAP name such as "\Seen" or a keyword such as "$Junk".
     */
    public function hasFlag(Flag|string $flag): bool
    {
        return in_array(Flag::normalise($flag), $this->flags, strict: true);
    }

    public function getContentType(): string
    {
        return $this->part->getContentType();
    }

    #[Override]
    public function isMultipart(): bool
    {
        return $this->part->isMultipart();
    }

    /**
     * @return list<Part>
     * @throws Exception\RuntimeException When the parts nest too deeply, are too many, or cannot be read.
     */
    #[Override]
    public function getParts(): array
    {
        return $this->part->getParts();
    }

    /**
     * @throws Exception\RuntimeException When the parts cannot be read.
     */
    public function countParts(): int
    {
        return $this->part->countParts();
    }

    /**
     * @throws Exception\RuntimeException When the body cannot be read.
     */
    #[Override]
    public function getContent(): string
    {
        return $this->part->getContent();
    }

    /**
     * @throws Exception\RuntimeException When the body cannot be read.
     */
    #[Override]
    public function getEncodedContent(): string
    {
        return $this->part->getEncodedContent();
    }

    /**
     * The plain-text body as UTF-8, or null when there is none, see Part::getTextBody().
     *
     * @throws Exception\RuntimeException When the parts cannot be read.
     */
    public function getTextBody(): ?string
    {
        return $this->part->getTextBody();
    }

    /**
     * The HTML body as UTF-8, or null when there is none; not sanitised, see Part::getHtmlBody().
     *
     * @throws Exception\RuntimeException When the parts cannot be read.
     */
    public function getHtmlBody(): ?string
    {
        return $this->part->getHtmlBody();
    }

    /**
     * The attachments and body of the message's first TNEF part (winmail.dat), see Part::getTnefContents().
     *
     * @throws Exception\RuntimeException When the parts cannot be read, or the TNEF part is malformed
     *     or exceeds the reader's limits.
     */
    public function getTnefContents(?Tnef\Reader $reader = null): ?Tnef\Contents
    {
        return $this->part->getTnefContents($reader);
    }

    /**
     * The size of the body as stored, in bytes.
     *
     * @throws Exception\RuntimeException When the body cannot be read.
     */
    public function getSize(): int
    {
        return $this->part->getSize();
    }

    /**
     * The whole message as written, unchanged headers as they were read, with CRLF line breaks.
     *
     * @throws Exception\RuntimeException When the body cannot be read.
     */
    public function toString(): string
    {
        return $this->part->toString();
    }

    /**
     * @return TreeIterator<int, Part>
     * @throws Exception\RuntimeException When the parts cannot be read.
     */
    #[Override]
    public function getIterator(): TreeIterator
    {
        return $this->part->getIterator();
    }

    private function addressList(string $name): AddressList
    {
        $header = $this->getHeaders()->get($name);

        return $header instanceof AbstractAddressList ? $header->getAddressList() : new AddressList();
    }
}
