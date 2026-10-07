<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Address;
use Contenir\Mail\Mime\Mime;

use function idn_to_ascii;
use function sprintf;
use function strrpos;
use function substr;

use const IDNA_DEFAULT;
use const INTL_IDNA_VARIANT_UTS46;

/**
 * Writes addresses as they appear on the wire.
 *
 * @internal Used by the address-list headers and Sender.
 */
final class AddressEncoder
{
    private function __construct() {}

    /**
     * The address as written on the wire: the display name quoted when it
     * holds specials and RFC 2047 encoded when it is not ASCII, and the
     * domain converted to its ASCII (punycode) form.
     */
    public static function encode(Address $address): string
    {
        $email = self::asciiEmail($address->getEmail());
        $name  = $address->getName();
        if (null === $name) {
            return $email;
        }

        // An encoded word is a single atom, so a name that needs encoding is encoded rather than quoted
        $name = Mime::isPrintable($name) ? Address::quoteDisplayName($name) : HeaderWrap::encodePhrase($name);

        return sprintf('%s <%s>', $name, $email);
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
                . (string) idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
        );
    }
}
