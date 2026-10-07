<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Headers;
use Override;

use function array_map;
use function implode;
use function in_array;
use function sprintf;
use function strtolower;

/**
 * Base for headers holding a list of addresses: From, To, Cc, Bcc and Reply-To.
 *
 * @api
 */
abstract readonly class AbstractAddressList implements HeaderInterface
{
    /** The canonical header name */
    protected const string FIELD_NAME = '';

    /** @var list<string> lower-cased spellings accepted when parsing */
    protected const array FIELD_NAMES = [];

    private AddressList $addressList;

    final public function __construct(?AddressList $addressList = null)
    {
        $this->addressList = $addressList ?? new AddressList();
    }

    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$fieldName, $fieldValue] = GenericHeader::splitHeaderLine($headerLine);
        if (! in_array(strtolower($fieldName), static::FIELD_NAMES, strict: true)) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Invalid header line for "%s" string',
                static::FIELD_NAME,
            ));
        }

        return new static(AddressListCodec::decode($fieldValue));
    }

    public function getAddressList(): AddressList
    {
        return $this->addressList;
    }

    public function withAddressList(AddressList $addressList): static
    {
        return new static($addressList);
    }

    #[Override]
    public function getFieldName(): string
    {
        return static::FIELD_NAME;
    }

    #[Override]
    public function getFieldValue(): string
    {
        return implode(
            ', ',
            array_map(static fn(Address $address): string => $address->toString(), $this->addressList->toArray()),
        );
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        return implode(',' . Headers::FOLDING, array_map(AddressEncoder::encode(...), $this->addressList->toArray()));
    }

    /**
     * An empty list writes no header at all.
     */
    #[Override]
    public function toString(): string
    {
        if ($this->addressList->isEmpty()) {
            return '';
        }

        return sprintf('%s: %s', static::FIELD_NAME, $this->getEncodedFieldValue());
    }
}
