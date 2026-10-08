<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Address;
use Contenir\Mail\AddressGroup;
use Contenir\Mail\Headers;
use Contenir\Mail\Mime\Mime;
use Contenir\Mail\Validator\DomainName;

use function idn_to_ascii;
use function implode;
use function sprintf;
use function strlen;
use function strrpos;
use function substr;

use const INTL_IDNA_VARIANT_UTS46;

/**
 * Writes addresses as they appear on the wire.
 *
 * @internal Used by the address-list headers and Sender.
 */
final class AddressEncoder
{
    /**
     * The longest quoted display name and address together that fit one line: 998 less
     * "Reply-To: ", the longest address header name, and the " <>," around the address.
     */
    private const int MAX_QUOTED_LENGTH = HeaderLines::MAX_LINE_LENGTH - 14;

    private function __construct() {}

    /**
     * The address as written on the wire: the display name quoted when it
     * holds specials, and RFC 2047 encoded when it is not ASCII or too long
     * for one line, and the domain converted to its ASCII (punycode) form.
     *
     * An encoded word is a single atom, so a name that needs encoding is
     * encoded rather than quoted.
     */
    public static function encode(Address $address): string
    {
        $email = self::asciiEmail($address->getEmail());
        $name  = $address->getName();
        if (null === $name) {
            return $email;
        }

        $quoted = Address::quoteDisplayName($name);
        if (Mime::isPrintable($name) && (strlen($quoted) + strlen($email)) <= self::MAX_QUOTED_LENGTH) {
            return sprintf('%s <%s>', $quoted, $email);
        }

        return sprintf('%s <%s>', HeaderWrap::encodePhrase($name), $email);
    }

    /**
     * A group as it is written: its name as a display name would be, then its members, then ";".
     */
    public static function encodeGroup(AddressGroup $group): string
    {
        $name    = $group->getName();
        $members = [];
        foreach ($group->getAddresses() as $address) {
            $members[] = self::encode($address);
        }

        return sprintf(
            '%s:%s;',
            Mime::isPrintable($name) ? Address::quoteDisplayName($name) : HeaderWrap::encodePhrase($name),
            [] === $members ? '' : ' ' . implode(',' . Headers::FOLDING, $members),
        );
    }

    /**
     * The address with its domain in ASCII (punycode) form.
     *
     * Address has already validated the domain through the same UTS #46
     * conversion, so the conversion cannot fail here.
     */
    private static function asciiEmail(string $email): string
    {
        $at     = (int) strrpos($email, needle: '@');
        $domain = substr($email, $at + 1);
        if (Mime::isPrintable($domain)) {
            return $email;
        }

        return (
            substr($email, offset: 0, length: $at)
                . '@'
                . (string) idn_to_ascii($domain, DomainName::IDNA_OPTIONS, INTL_IDNA_VARIANT_UTS46)
        );
    }
}
