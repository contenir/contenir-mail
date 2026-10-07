<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Override;

use function count;
use function explode;
use function ltrim;
use function str_replace;
use function ucwords;

/**
 * Any header without a dedicated class, and the fallback for headers that
 * fail to parse as their dedicated class.
 */
final readonly class GenericHeader implements HeaderInterface
{
    private string $fieldName;

    private string $fieldValue;

    /**
     * @throws Exception\InvalidArgumentException When the name or value is invalid, or the name is longer than HeaderName::MAX_LENGTH.
     */
    public function __construct(string $fieldName, string $fieldValue = '')
    {
        HeaderName::assertLength($fieldName);
        /** Normalise "content_type" and "content type" to "Content-Type" */
        $fieldName = str_replace(
            search: ' ',
            replace: '-',
            subject: ucwords(str_replace(
                search: ['_', '-'],
                replace: ' ',
                subject: $fieldName,
            )),
        );
        if (! HeaderName::isValid($fieldName)) {
            throw new Exception\InvalidArgumentException(
                'Header name must be composed of printable US-ASCII characters, except colon.',
            );
        }

        if (! HeaderWrap::canBeEncoded($fieldValue)) {
            throw new Exception\InvalidArgumentException(
                'Header value must be composed of printable US-ASCII characters and valid folding sequences.',
            );
        }

        $this->fieldName  = $fieldName;
        $this->fieldValue = $fieldValue;
    }

    /**
     * @throws Exception\InvalidArgumentException When the line is malformed or the name is longer than HeaderName::MAX_LENGTH.
     */
    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$name, $value] = self::splitHeaderLine($headerLine);

        return new self($name, HeaderWrap::mimeDecodeValue($value));
    }

    /**
     * Split a header line into its name and its value, with leading whitespace removed from the value.
     *
     * @return array{string, string}
     * @throws Exception\InvalidArgumentException When the line is not `name: value` or either part is invalid.
     */
    public static function splitHeaderLine(string $headerLine): array
    {
        $parts = explode(':', $headerLine, limit: 2);
        if (2 !== count($parts)) {
            throw new Exception\InvalidArgumentException('Header must match with the format "name:value"');
        }

        $name  = $parts[0];
        $value = $parts[1] ?? '';
        if (! HeaderName::isValid($name)) {
            throw new Exception\InvalidArgumentException('Invalid header name detected');
        }

        if (! HeaderValue::isValidUtf8($value)) {
            throw new Exception\InvalidArgumentException('Invalid header value detected');
        }

        return [$name, ltrim($value)];
    }

    #[Override]
    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    #[Override]
    public function getFieldValue(): string
    {
        return $this->fieldValue;
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        return HeaderWrap::fold($this->fieldName, $this->fieldValue);
    }

    #[Override]
    public function toString(): string
    {
        return HeaderWrap::line($this->fieldName, $this->getEncodedFieldValue());
    }
}
