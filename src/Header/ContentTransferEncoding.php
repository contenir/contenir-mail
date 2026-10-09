<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Mime\TransferEncoding;
use Override;

use function in_array;
use function sprintf;
use function strtolower;
use function trim;

/**
 * @api
 */
final readonly class ContentTransferEncoding implements HeaderInterface
{
    public function __construct(
        private TransferEncoding $transferEncoding,
    ) {}

    /**
     * @throws Exception\InvalidArgumentException When the line is not a Content-Transfer-Encoding header
     *     or names an unknown mechanism.
     */
    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$name, $value] = GenericHeader::splitHeaderLine($headerLine);
        $names = ['contenttransferencoding', 'content_transfer_encoding', 'content-transfer-encoding'];
        if (! in_array(strtolower($name), $names, strict: true)) {
            throw new Exception\InvalidArgumentException('Invalid header line for Content-Transfer-Encoding string');
        }

        // The mechanism is case-insensitive (RFC 2045, section 6.1)
        $value    = strtolower(trim(HeaderWrap::mimeDecodeValue($value)));
        $encoding = TransferEncoding::tryFrom($value);
        if (null === $encoding) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Unknown Content-Transfer-Encoding "%s"',
                $value,
            ));
        }

        return new self($encoding);
    }

    public function getTransferEncoding(): TransferEncoding
    {
        return $this->transferEncoding;
    }

    #[Override]
    public function getFieldName(): string
    {
        return 'Content-Transfer-Encoding';
    }

    #[Override]
    public function getFieldValue(): string
    {
        return $this->transferEncoding->value;
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        return $this->transferEncoding->value;
    }

    #[Override]
    public function toString(): string
    {
        return "Content-Transfer-Encoding: {$this->transferEncoding->value}";
    }
}
