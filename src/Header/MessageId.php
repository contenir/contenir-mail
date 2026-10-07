<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Override;
use Random\RandomException;

use function bin2hex;
use function gethostname;
use function preg_match;
use function random_bytes;
use function sprintf;
use function strtolower;
use function trim;

/**
 * The unique identifier of a message (RFC 5322, section 3.6.4), held without its angle brackets.
 */
final readonly class MessageId implements HeaderInterface
{
    private string $id;

    /**
     * @throws Exception\InvalidArgumentException When the ID is empty or contains invalid characters.
     */
    public function __construct(string $id)
    {
        $id = trim($id, characters: " \t<>");
        if ('' === $id || ! HeaderValue::isValid($id) || 1 === preg_match("/[\r\n\\s<>]/", $id)) {
            throw new Exception\InvalidArgumentException('Invalid ID detected');
        }

        $this->id = $id;
    }

    /**
     * A new globally unique ID on the given host, or on this machine's host name.
     *
     * @throws RandomException When the system has no source of randomness.
     */
    public static function generate(?string $host = null): self
    {
        $host ??= gethostname();

        return new self(sprintf('%s@%s', bin2hex(random_bytes(16)), false === $host ? 'localhost' : $host));
    }

    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$name, $value] = GenericHeader::splitHeaderLine($headerLine);
        if ('message-id' !== strtolower($name)) {
            throw new Exception\InvalidArgumentException('Invalid header line for Message-ID string');
        }

        return new self(HeaderWrap::mimeDecodeValue($value));
    }

    public function getId(): string
    {
        return $this->id;
    }

    #[Override]
    public function getFieldName(): string
    {
        return 'Message-ID';
    }

    #[Override]
    public function getFieldValue(): string
    {
        return "<{$this->id}>";
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        return $this->getFieldValue();
    }

    #[Override]
    public function toString(): string
    {
        return "Message-ID: {$this->getFieldValue()}";
    }
}
