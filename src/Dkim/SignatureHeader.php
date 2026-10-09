<?php

declare(strict_types=1);

namespace Contenir\Mail\Dkim;

use Contenir\Mail\Dkim\Exception\InvalidArgumentException;
use Contenir\Mail\Header\Exception\InvalidArgumentException as HeaderInvalidArgumentException;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Header\HeaderInterface;
use Contenir\Mail\Header\HeaderValue;
use Override;

use function preg_replace;
use function strcasecmp;

/**
 * A DKIM-Signature header, written exactly as it was signed.
 *
 * The value is kept folded as the signer folded it, since with "simple"
 * canonicalisation any change to the folding breaks the signature.
 *
 * @api
 */
final readonly class SignatureHeader implements HeaderInterface
{
    public const string NAME = 'DKIM-Signature';

    private function __construct(
        private string $value,
    ) {}

    /**
     * @throws HeaderInvalidArgumentException When the line is not `name: value`.
     * @throws InvalidArgumentException When the line is not a DKIM-Signature header of printable US-ASCII, folded with CRLF.
     */
    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$name, $value] = GenericHeader::splitHeaderLine($headerLine);
        if (0 !== strcasecmp($name, self::NAME)) {
            throw new InvalidArgumentException('Invalid header line for DKIM-Signature string');
        }

        if (! HeaderValue::isValid($value)) {
            throw new InvalidArgumentException(
                'A DKIM-Signature value must be printable US-ASCII, folded with CRLF and white space',
            );
        }

        return new self($value);
    }

    #[Override]
    public function getFieldName(): string
    {
        return self::NAME;
    }

    /**
     * The value unfolded.
     */
    #[Override]
    public function getFieldValue(): string
    {
        return (string) preg_replace('/\r\n(?=[ \t])/', replacement: '', subject: $this->value);
    }

    /**
     * The value folded, as it was signed.
     */
    #[Override]
    public function getEncodedFieldValue(): string
    {
        return $this->value;
    }

    #[Override]
    public function toString(): string
    {
        return self::NAME . ': ' . $this->value;
    }
}
