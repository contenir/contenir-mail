<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Override;

use function preg_match;
use function strtolower;

/**
 * A trace field added by each server that handled the message (RFC 5322, section 3.6.7).
 *
 * The value is kept as written; it is not split into its from, by and date parts.
 */
final readonly class Received implements HeaderInterface
{
    /** A line longer than 998 less "Received: "; the value is written as it is, so it cannot be refolded */
    private const string OVERLONG_LINE = '/[^\r\n]{989}/';

    private string $value;

    /**
     * @throws Exception\InvalidArgumentException When the value contains invalid characters or a line too long.
     */
    public function __construct(string $value)
    {
        if (! HeaderValue::isValid($value)) {
            throw new Exception\InvalidArgumentException('Invalid Received value provided');
        }

        if (1 === preg_match(self::OVERLONG_LINE, $value)) {
            throw new Exception\InvalidArgumentException(
                'A Received line may be at most 988 characters, so that it fits in 998 with its name',
            );
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
