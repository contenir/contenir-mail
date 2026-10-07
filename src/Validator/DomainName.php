<?php

declare(strict_types=1);

namespace Contenir\Mail\Validator;

use Contenir\Mail\Utf8;

use function array_pop;
use function count;
use function explode;
use function idn_to_ascii;
use function preg_match;

use const IDNA_CHECK_BIDI;
use const IDNA_CHECK_CONTEXTJ;
use const IDNA_NONTRANSITIONAL_TO_ASCII;
use const INTL_IDNA_VARIANT_UTS46;

/**
 * Local network and Internet domain name syntax, as laminas-validator 2's
 * Hostname validator accepted it with ALLOW_DNS | ALLOW_LOCAL.
 *
 * Internationalised names are accepted when they convert to ASCII under
 * UTS #46, rather than only for the TLDs laminas-validator kept tables for.
 *
 * @internal
 */
final class DomainName
{
    /**
     * UTS #46 options for converting names to ASCII: IDNA2008 (non-transitional)
     * mapping, so "ß" and "ς" are kept rather than folded, and the bidi and
     * CONTEXTJ rules that reject labels mixing directions or misusing joiners,
     * which are common in look-alike (homograph) domains.
     */
    public const int IDNA_OPTIONS = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;

    /** laminas-validator's local network name pattern, kept verbatim for compatibility */
    private const string LOCAL_NAME = '/^(([a-zA-Z0-9\x2d]{1,63}\x2e)*[a-zA-Z0-9\x2d]{1,63}[\x2e]{0,1}){1,254}$/';

    private const string TLD = '/^([a-z]{2,63}|xn--[a-z0-9-]{1,59})$/i';

    private const string LABEL = '/^[a-z0-9-]{1,63}$/i';

    private const string SUBDOMAIN_LABEL = '/^[a-z0-9_-]{1,63}$/i';

    public static function isLocalOrDnsName(string $value): bool
    {
        return 1 === preg_match(self::LOCAL_NAME, $value) || self::isDnsName($value);
    }

    /**
     * Internet domain names add what local network names lack: "_" in
     * labels below the registrable domain, and internationalised labels.
     * UTS #46 conversion also rejects labels with a leading or trailing
     * dash, or "--" in the third and fourth positions outside punycode.
     */
    private static function isDnsName(string $value): bool
    {
        $length = Utf8::length($value);
        if ($length < 4 || $length > 253) {
            return false;
        }

        $ascii = idn_to_ascii($value, self::IDNA_OPTIONS, INTL_IDNA_VARIANT_UTS46);
        if (false === $ascii) {
            return false;
        }

        $labels = explode('.', $ascii);
        $tld    = array_pop($labels);
        if ([] === $labels || 1 !== preg_match(self::TLD, $tld)) {
            return false;
        }

        $registrable = count($labels) - 1;
        foreach ($labels as $index => $label) {
            $pattern = $index < $registrable ? self::SUBDOMAIN_LABEL : self::LABEL;
            if (1 !== preg_match($pattern, $label)) {
                return false;
            }
        }

        return true;
    }
}
