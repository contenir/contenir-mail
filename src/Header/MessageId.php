<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Override;
use Random\RandomException;

use function bin2hex;
use function preg_match;
use function random_bytes;
use function sprintf;
use function str_contains;
use function strtolower;
use function trim;

/**
 * The unique identifier of a message (RFC 5322, section 3.6.4), held without its angle brackets.
 */
final readonly class MessageId implements HeaderInterface
{
    /** The domain of generated IDs when none is given: reserved, so it names no real host (RFC 2606) */
    public const string DEFAULT_DOMAIN = 'localhost.invalid';

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
     * A new globally unique ID: 128 random bits at the given domain.
     *
     * Pass the sender's domain. The default, "localhost.invalid", names no
     * real host, so the ID never reveals the name of the machine that sent it.
     *
     * @throws Exception\InvalidArgumentException When the domain would make an invalid ID.
     * @throws RandomException When the system has no source of randomness.
     */
    public static function generate(string $domain = self::DEFAULT_DOMAIN): self
    {
        if (str_contains($domain, '@')) {
            throw new Exception\InvalidArgumentException('Invalid ID detected');
        }

        return new self(sprintf('%s@%s', bin2hex(random_bytes(16)), $domain));
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
