<?php

declare(strict_types=1);

namespace Contenir\Mail;

use function implode;
use function preg_match;
use function sprintf;
use function trim;

/**
 * A named group of addresses in an address-list header (RFC 5322, section 3.4),
 * such as "Team: jo@example.org, sam@example.org;".
 *
 * A group may be empty: "undisclosed-recipients:;" is the usual To header of a
 * message sent only to Bcc recipients.
 *
 * @api
 */
final readonly class AddressGroup
{
    /** C0 controls, DEL and C1 controls, which a group name may not hold */
    private const string CONTROLS = '/[\x00-\x08\x0A-\x1F\x7F\x{80}-\x{9F}]/u';

    private string $name;

    /**
     * @throws Exception\InvalidArgumentException When the name is empty, not UTF-8 or holds a control character.
     */
    public function __construct(
        string $name,
        private AddressList $addresses = new AddressList(),
    ) {
        $name = trim($name);
        if ('' === $name) {
            throw new Exception\InvalidArgumentException('An address group needs a name');
        }

        if (1 !== preg_match('//u', $name)) {
            throw new Exception\InvalidArgumentException('An address group name must be UTF-8 text');
        }

        if (1 === preg_match(self::CONTROLS, $name)) {
            throw new Exception\InvalidArgumentException('An address group name must not contain control characters');
        }

        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAddresses(): AddressList
    {
        return $this->addresses;
    }

    /**
     * The group as it is displayed: `Team: jo@example.org, sam@example.org;`, or `Team:;` when empty.
     */
    public function toString(): string
    {
        $members = [];
        foreach ($this->addresses as $address) {
            $members[] = $address->toString();
        }

        return sprintf(
            '%s:%s;',
            Address::quoteDisplayName($this->name),
            [] === $members ? '' : ' ' . implode(', ', $members),
        );
    }
}
