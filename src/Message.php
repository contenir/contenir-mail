<?php

declare(strict_types=1);

namespace Contenir\Mail;

use Contenir\Mail\Header\Bcc;
use Contenir\Mail\Header\Cc;
use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Header\Date;
use Contenir\Mail\Header\From;
use Contenir\Mail\Header\HeaderInterface;
use Contenir\Mail\Header\MimeVersion;
use Contenir\Mail\Header\ReplyTo;
use Contenir\Mail\Header\Sender;
use Contenir\Mail\Header\Subject;
use Contenir\Mail\Header\To;
use DateTimeImmutable;
use Stringable;

use function array_shift;
use function is_string;

/**
 * An e-mail message, built step by step.
 *
 * The message is mutable, but everything it holds (headers, addresses) is
 * an immutable value, so a cloned message can be changed without touching
 * the original.
 *
 * @mago-expect lint:too-many-methods The builder exposes set, add and get for each address header, as in Zend_Mail.
 */
final class Message
{
    private Headers $headers;

    private string|Stringable|Mime\Message|null $body = null;

    private string $encoding = 'ASCII';

    public function __construct(?Headers $headers = null)
    {
        $this->headers = $headers ?? new Headers(new Date(new DateTimeImmutable()));
    }

    /**
     * A message needs at least one From address to be valid (RFC 5322, section 3.6).
     */
    public function isValid(): bool
    {
        return ! $this->getFrom()->isEmpty();
    }

    /**
     * The character set of the body. Headers choose their own encoding.
     */
    public function setEncoding(string $encoding): self
    {
        $this->encoding = $encoding;

        return $this;
    }

    public function getEncoding(): string
    {
        return $this->encoding;
    }

    public function setHeaders(Headers $headers): self
    {
        $this->headers = $headers;

        return $this;
    }

    public function getHeaders(): Headers
    {
        return $this->headers;
    }

    /**
     * Set a header, replacing any headers of the same name.
     */
    public function setHeader(HeaderInterface $header): self
    {
        $this->headers = $this->headers->with($header);

        return $this;
    }

    /**
     * Add a header, keeping any of the same name.
     */
    public function addHeader(HeaderInterface $header): self
    {
        $this->headers = $this->headers->withAdded($header);

        return $this;
    }

    public function removeHeader(string $name): self
    {
        $this->headers = $this->headers->without($name);

        return $this;
    }

    /**
     * @param Address|AddressList|string|iterable<int|string, Address|string|null> $addresses
     */
    public function setFrom(Address|AddressList|string|iterable $addresses, ?string $name = null): self
    {
        return $this->setAddressList(new From(self::toAddressList($addresses, $name)));
    }

    /**
     * @param Address|AddressList|string|iterable<int|string, Address|string|null> $addresses
     */
    public function addFrom(Address|AddressList|string|iterable $addresses, ?string $name = null): self
    {
        return $this->setAddressList(new From($this->getFrom()->withList(self::toAddressList($addresses, $name))));
    }

    public function getFrom(): AddressList
    {
        return $this->getAddressList('From');
    }

    /**
     * @param Address|AddressList|string|iterable<int|string, Address|string|null> $addresses
     */
    public function setTo(Address|AddressList|string|iterable $addresses, ?string $name = null): self
    {
        return $this->setAddressList(new To(self::toAddressList($addresses, $name)));
    }

    /**
     * @param Address|AddressList|string|iterable<int|string, Address|string|null> $addresses
     */
    public function addTo(Address|AddressList|string|iterable $addresses, ?string $name = null): self
    {
        return $this->setAddressList(new To($this->getTo()->withList(self::toAddressList($addresses, $name))));
    }

    public function getTo(): AddressList
    {
        return $this->getAddressList('To');
    }

    /**
     * @param Address|AddressList|string|iterable<int|string, Address|string|null> $addresses
     */
    public function setCc(Address|AddressList|string|iterable $addresses, ?string $name = null): self
    {
        return $this->setAddressList(new Cc(self::toAddressList($addresses, $name)));
    }

    /**
     * @param Address|AddressList|string|iterable<int|string, Address|string|null> $addresses
     */
    public function addCc(Address|AddressList|string|iterable $addresses, ?string $name = null): self
    {
        return $this->setAddressList(new Cc($this->getCc()->withList(self::toAddressList($addresses, $name))));
    }

    public function getCc(): AddressList
    {
        return $this->getAddressList('Cc');
    }

    /**
     * @param Address|AddressList|string|iterable<int|string, Address|string|null> $addresses
     */
    public function setBcc(Address|AddressList|string|iterable $addresses, ?string $name = null): self
    {
        return $this->setAddressList(new Bcc(self::toAddressList($addresses, $name)));
    }

    /**
     * @param Address|AddressList|string|iterable<int|string, Address|string|null> $addresses
     */
    public function addBcc(Address|AddressList|string|iterable $addresses, ?string $name = null): self
    {
        return $this->setAddressList(new Bcc($this->getBcc()->withList(self::toAddressList($addresses, $name))));
    }

    public function getBcc(): AddressList
    {
        return $this->getAddressList('Bcc');
    }

    /**
     * @param Address|AddressList|string|iterable<int|string, Address|string|null> $addresses
     */
    public function setReplyTo(Address|AddressList|string|iterable $addresses, ?string $name = null): self
    {
        return $this->setAddressList(new ReplyTo(self::toAddressList($addresses, $name)));
    }

    /**
     * @param Address|AddressList|string|iterable<int|string, Address|string|null> $addresses
     */
    public function addReplyTo(Address|AddressList|string|iterable $addresses, ?string $name = null): self
    {
        $addressList = $this->getReplyTo()->withList(self::toAddressList($addresses, $name));

        return $this->setAddressList(new ReplyTo($addressList));
    }

    public function getReplyTo(): AddressList
    {
        return $this->getAddressList('Reply-To');
    }

    public function setSender(Address|string $emailOrAddress, ?string $name = null): self
    {
        $address = is_string($emailOrAddress) ? new Address($emailOrAddress, $name) : $emailOrAddress;

        return $this->setHeader(new Sender($address));
    }

    public function getSender(): ?Address
    {
        $header = $this->headers->get('Sender');

        return $header instanceof Sender ? $header->getAddress() : null;
    }

    public function setSubject(string $subject): self
    {
        return $this->setHeader(new Subject($subject));
    }

    public function getSubject(): ?string
    {
        return $this->headers->get('Subject')?->getFieldValue();
    }

    /**
     * Set the body as text, a MIME message, or any object that can be cast to a string.
     *
     * A MIME message also sets MIME-Version, and the Content-Type of a
     * multipart body or the content headers of a single part.
     */
    public function setBody(string|Stringable|Mime\Message|null $body): self
    {
        $this->body = $body;
        if (! $body instanceof Mime\Message) {
            return $this;
        }

        $this->setHeader(new MimeVersion());

        if ($body->isMultiPart()) {
            return $this->setHeader(new ContentType('multipart/mixed', ['boundary' => $body->getMime()->boundary()]));
        }

        $parts = $body->getParts();
        $part  = array_shift($parts);
        if (null !== $part) {
            /** @var list<array{string, string}> $partHeaders */
            $partHeaders = $part->getHeadersArray(Headers::EOL);
            foreach (Headers::fromIterable($partHeaders) as $header) {
                $this->setHeader($header);
            }
        }

        return $this;
    }

    public function getBody(): string|Stringable|Mime\Message|null
    {
        return $this->body;
    }

    public function getBodyText(): string
    {
        if ($this->body instanceof Mime\Message) {
            return $this->body->generateMessage(Headers::EOL);
        }

        return (string) $this->body;
    }

    public function toString(): string
    {
        return $this->headers->toString() . Headers::EOL . $this->getBodyText();
    }

    /**
     * Parse a raw message into its headers and body text.
     */
    public static function fromString(string $rawMessage): self
    {
        $headers = new Headers();
        $content = '';
        Mime\Decode::splitMessage($rawMessage, $headers, $content, Headers::EOL);

        return (new self($headers))->setBody($content);
    }

    private function getAddressList(string $headerName): AddressList
    {
        $header = $this->headers->get($headerName);

        return $header instanceof Header\AbstractAddressList ? $header->getAddressList() : new AddressList();
    }

    private function setAddressList(Header\AbstractAddressList $header): self
    {
        return $this->setHeader($header);
    }

    /**
     * @param Address|AddressList|string|iterable<int|string, Address|string|null> $addresses
     * @throws Exception\InvalidArgumentException When a name is given with anything but an e-mail address string.
     */
    private static function toAddressList(Address|AddressList|string|iterable $addresses, ?string $name): AddressList
    {
        if (null !== $name && ! is_string($addresses)) {
            throw new Exception\InvalidArgumentException(
                'A display name can only be given with a single e-mail address',
            );
        }

        return match (true) {
            $addresses instanceof AddressList => $addresses,
            $addresses instanceof Address => new AddressList($addresses),
            is_string($addresses) && null !== $name => new AddressList(new Address($addresses, $name)),
            is_string($addresses) => new AddressList(Address::fromString($addresses)),
            default               => AddressList::fromIterable($addresses),
        };
    }
}
