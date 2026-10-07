<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Override;

use function strtolower;

final readonly class Subject implements HeaderInterface
{
    private string $subject;

    /**
     * @throws Exception\InvalidArgumentException When the subject cannot be encoded.
     */
    public function __construct(string $subject)
    {
        if (! HeaderWrap::canBeEncoded($subject)) {
            throw new Exception\InvalidArgumentException(
                'Subject value must be composed of printable US-ASCII or UTF-8 characters.',
            );
        }

        $this->subject = $subject;
    }

    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$name, $value] = GenericHeader::splitHeaderLine($headerLine);
        if ('subject' !== strtolower($name)) {
            throw new Exception\InvalidArgumentException('Invalid header line for Subject string');
        }

        return new self(HeaderWrap::mimeDecodeValue($value));
    }

    #[Override]
    public function getFieldName(): string
    {
        return 'Subject';
    }

    #[Override]
    public function getFieldValue(): string
    {
        return $this->subject;
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        return HeaderWrap::fold('Subject', $this->subject);
    }

    #[Override]
    public function toString(): string
    {
        return HeaderWrap::line('Subject', $this->getEncodedFieldValue());
    }
}
