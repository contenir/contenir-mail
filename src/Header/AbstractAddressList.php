<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Address;
use Contenir\Mail\AddressGroup;
use Contenir\Mail\AddressList;
use Contenir\Mail\Headers;
use NoDiscard;
use Override;

use function array_filter;
use function array_map;
use function array_values;
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

    /** @var list<Address|AddressGroup> */
    private array $entries;

    /**
     * Addresses and groups in the order they are written; an address list
     * stands for its addresses.
     */
    final public function __construct(Address|AddressList|AddressGroup ...$entries)
    {
        $list = [];
        foreach ($entries as $entry) {
            foreach ($entry instanceof AddressList ? $entry->toArray() : [$entry] as $item) {
                $list[] = $item;
            }
        }

        $this->entries = $list;
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

        return new static(...AddressListCodec::decodeEntries($fieldValue));
    }

    /**
     * Every address, including the members of groups, in order: the recipients the header names.
     */
    public function getAddressList(): AddressList
    {
        $addresses = [];
        foreach ($this->entries as $entry) {
            foreach ($entry instanceof AddressGroup ? $entry->getAddresses() : [$entry] as $address) {
                $addresses[] = $address;
            }
        }

        return new AddressList(...$addresses);
    }

    /**
     * @return list<AddressGroup>
     */
    public function getGroups(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn(Address|AddressGroup $entry): bool => $entry instanceof AddressGroup,
        ));
    }

    /**
     * The same header with these addresses in place of the addresses outside groups; groups are kept.
     */
    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function withAddressList(AddressList $addressList): static
    {
        return new static($addressList, ...$this->getGroups());
    }

    /**
     * The same header with a group, or addresses, added after its entries.
     * An address already in the header outside a group is not added again.
     */
    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function withAdded(Address|AddressList|AddressGroup $entry): static
    {
        if ($entry instanceof AddressGroup) {
            return new static(...[...$this->entries, $entry]);
        }

        $present = new AddressList(...array_filter(
            $this->entries,
            static fn(Address|AddressGroup $existing): bool => $existing instanceof Address,
        ));
        $added = [];
        foreach ($entry instanceof AddressList ? $entry : [$entry] as $address) {
            if ($present->has($address->getEmail())) {
                continue;
            }

            $present = $present->with($address);
            $added[] = $address;
        }

        return new static(...[...$this->entries, ...$added]);
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
            array_map(static fn(Address|AddressGroup $entry): string => $entry->toString(), $this->entries),
        );
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        return implode(',' . Headers::FOLDING, array_map(
            static fn(Address|AddressGroup $entry): string => $entry instanceof AddressGroup
                ? AddressEncoder::encodeGroup($entry)
                : AddressEncoder::encode($entry),
            $this->entries,
        ));
    }

    /**
     * A header with no addresses and no groups writes nothing at all; an empty group is written.
     */
    #[Override]
    public function toString(): string
    {
        if ([] === $this->entries) {
            return '';
        }

        return sprintf('%s: %s', static::FIELD_NAME, $this->getEncodedFieldValue());
    }
}
