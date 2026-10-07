<?php

declare(strict_types=1);

namespace Contenir\Mail;

use Contenir\Mail\Header\Bcc;
use Contenir\Mail\Header\Cc;
use Contenir\Mail\Header\Date;
use Contenir\Mail\Header\From;
use Contenir\Mail\Header\HeaderInterface;
use Contenir\Mail\Header\MimeVersion;
use Contenir\Mail\Header\ReplyTo;
use Contenir\Mail\Header\Sender;
use Contenir\Mail\Header\Subject;
use Contenir\Mail\Header\To;
use Psr\Clock\ClockInterface;
use Stringable;

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
    /** Shown by mail readers that do not understand MIME, ahead of the first part */
    private const string PREAMBLE = 'This is a multi-part message in MIME format.';

    private Headers $headers;

    private string|Stringable|Mime\PartInterface|null $body = null;

    private Mime\Body $parts;

    /** The MIME tree built from $parts, kept so its boundaries stay the same */
    private ?Mime\PartInterface $composed = null;

    /**
     * @param Headers|null $headers The headers to start from; a Date header from the clock when none are given.
     */
    public function __construct(?Headers $headers = null, ClockInterface $clock = new SystemClock())
    {
        $this->headers = $headers ?? new Headers(new Date($clock->now()));
        $this->parts   = new Mime\Body();
    }

    /**
     * A message needs at least one From address to be valid (RFC 5322, section 3.6).
     */
    public function isValid(): bool
    {
        return ! $this->getFrom()->isEmpty();
    }

    public function setHeaders(Headers $headers): self
    {
        $this->headers = $headers;

        return $this;
    }

    /**
     * The message headers, with MIME-Version and the content headers of a MIME body added.
     *
     * @throws Mime\Exception\RuntimeException When embed() was used without setHtml().
     */
    public function getHeaders(): Headers
    {
        $body = $this->getBody();
        if (! $body instanceof Mime\PartInterface) {
            return $this->headers;
        }

        $headers = $this->headers->with(new MimeVersion());
        foreach ($body->getHeaders() as $header) {
            $headers = $headers->with($header);
        }

        return $headers;
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
     * The plain-text body. With HTML as well, the two become alternatives.
     */
    public function setText(string $text, string $charset = 'UTF-8'): self
    {
        return $this->setParts($this->parts->withText(Mime\Part::text($text, $charset)));
    }

    /**
     * The HTML body. With text as well, the two become alternatives.
     */
    public function setHtml(string $html, string $charset = 'UTF-8'): self
    {
        return $this->setParts($this->parts->withHtml(Mime\Part::html($html, $charset)));
    }

    /**
     * Attach a file, such as one built with Mime\Attachment::fromPath().
     */
    public function attach(Mime\Part $attachment): self
    {
        return $this->setParts($this->parts->withAttachment($attachment));
    }

    /**
     * Embed a resource the HTML refers to as "cid:…", such as one built with Mime\Attachment::inline().
     *
     * @throws Mime\Exception\InvalidArgumentException When the part has no Content-ID.
     */
    public function embed(Mime\Part $resource): self
    {
        return $this->setParts($this->parts->withEmbedded($resource));
    }

    /**
     * Set the body directly, as text or a MIME tree, in place of the parts
     * given to setText(), setHtml(), attach() and embed().
     *
     * A text body is sent as it is, so declare its Content-Type yourself if it is not ASCII.
     */
    public function setBody(string|Stringable|Mime\PartInterface|null $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * The body set with setBody(), or else the MIME tree built from the
     * text, HTML, embedded resources and attachments; null when there is none.
     *
     * @throws Mime\Exception\RuntimeException When embed() was used without setHtml().
     */
    public function getBody(): string|Stringable|Mime\PartInterface|null
    {
        return $this->body ?? ($this->composed ??= $this->parts->toPart());
    }

    /**
     * @throws Mime\Exception\RuntimeException When a multipart set with setBody() has no boundary,
     *     or embed() was used without setHtml().
     */
    public function getBodyText(): string
    {
        $body = $this->getBody();
        if (! $body instanceof Mime\PartInterface) {
            return (string) $body;
        }

        $text = Mime\PartWriter::body($body);

        return $body->isMultipart() ? self::PREAMBLE . Headers::EOL . Headers::EOL . $text : $text;
    }

    /**
     * @throws Mime\Exception\RuntimeException When a multipart set with setBody() has no boundary,
     *     or embed() was used without setHtml().
     */
    public function toString(): string
    {
        return $this->getHeaders()->toString() . Headers::EOL . $this->getBodyText();
    }

    /**
     * Parse a raw message into its headers and body text.
     *
     * Headers keep the text they were read with, so toString() writes them
     * back unchanged until they are replaced.
     *
     * @throws Exception\RuntimeException When the header block is malformed or too large.
     */
    public static function fromString(string $rawMessage): self
    {
        $headers = new Headers();
        $content = '';
        Mime\Decode::splitMessage($rawMessage, $headers, $content, Headers::EOL);

        return (new self($headers))->setBody($content);
    }

    private function setParts(Mime\Body $parts): self
    {
        $this->parts    = $parts;
        $this->composed = null;

        return $this;
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
