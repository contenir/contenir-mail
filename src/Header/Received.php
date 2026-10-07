<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Override;

use function strtolower;

/**
 * A trace field added by each server that handled the message (RFC 5322, section 3.6.7).
 *
 * The value is kept as written; it is not split into its from, by and date parts.
 */
final readonly class Received implements HeaderInterface
{
    private string $value;

    /**
     * @throws Exception\InvalidArgumentException When the value contains invalid characters.
     */
    public function __construct(string $value)
    {
        if (! HeaderValue::isValid($value)) {
            throw new Exception\InvalidArgumentException('Invalid Received value provided');
        }

        $this->value = $value;
    }

    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$name, $value] = GenericHeader::splitHeaderLine($headerLine);
        if ('received' !== strtolower($name)) {
            throw new Exception\InvalidArgumentException('Invalid header line for Received string');
        }

        return new self(HeaderWrap::mimeDecodeValue($value));
    }

    #[Override]
    public function getFieldName(): string
    {
        return 'Received';
    }

    #[Override]
    public function getFieldValue(): string
    {
        return $this->value;
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        return $this->value;
    }

    #[Override]
    public function toString(): string
    {
        return "Received: {$this->value}";
    }
}
