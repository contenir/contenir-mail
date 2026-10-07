<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Address;
use Override;

use function preg_match;
use function strtolower;
use function trim;

/**
 * The mailbox responsible for sending a message on behalf of its author (RFC 5322, section 3.6.2).
 */
final readonly class Sender implements HeaderInterface
{
    public function __construct(
        private Address $address,
    ) {}

    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$name, $value] = GenericHeader::splitHeaderLine($headerLine);
        if ('sender' !== strtolower($name)) {
            throw new Exception\InvalidArgumentException('Invalid header name for Sender string');
        }

        $value   = HeaderWrap::mimeDecodeValue($value);
        $matches = [];
        if (
            1 !== preg_match(
                '/^(?:(?P<name>.+)\s)?(?(name)<|<?)(?P<email>[^\s]+?)(?(name)>|>?)$/',
                $value,
                $matches,
            )
        ) {
            throw new Exception\InvalidArgumentException('Invalid header value for Sender string');
        }

        $senderName = trim($matches['name'] ?? '', characters: " \t\"");

        return new self(new Address($matches['email'] ?? '', '' === $senderName ? null : $senderName));
    }

    public function getAddress(): Address
    {
        return $this->address;
    }

    #[Override]
    public function getFieldName(): string
    {
        return 'Sender';
    }

    #[Override]
    public function getFieldValue(): string
    {
        return $this->address->toString();
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        return AddressEncoder::encode($this->address);
    }

    #[Override]
    public function toString(): string
    {
        return "Sender: {$this->getEncodedFieldValue()}";
    }
}
