<?php

declare(strict_types=1);

namespace Contenir\Mail\Validator;

use function idn_to_ascii;
use function preg_match;
use function sprintf;
use function str_contains;
use function strlen;

use const INTL_IDNA_VARIANT_UTS46;

/**
 * Validates e-mail addresses the way laminas-validator 2's EmailAddress
 * validator did with Hostname::ALLOW_DNS | Hostname::ALLOW_LOCAL and its
 * default options: strict length limits, no MX lookup.
 *
 * @internal
 */
final class EmailAddressValidator
{
    public const string INVALID_FORMAT = 'The input is not a valid email address. Use the basic format local-part@hostname';

    public const string LENGTH_EXCEEDED = 'The input exceeds the allowed length';

    /** RFC 5322 atext */
    private const string ATEXT = 'a-zA-Z0-9\x21\x23\x24\x25\x26\x27\x2a\x2b\x2d\x2f\x3d\x3f\x5e\x5f\x60\x7b\x7c\x7d\x7e';

    /** A dot-atom of atext and, as RFC 6532 allows, UTF-8 */
    private const string DOT_ATOM =
        '/^[' . self::ATEXT . '\x{80}-\x{FFFF}]+(\x2e+[' . self::ATEXT . '\x{80}-\x{FFFF}]+)*$/u';

    /** @var list<string> */
    private array $messages = [];

    /**
     * The address splits at its last "@": the greedy local part runs to it, so the host name
     * after it, which holds no "@", always runs to the end and needs no end anchor.
     */
    public function isValid(string $value): bool
    {
        $this->messages = [];

        $matches = [];
        if (str_contains($value, '..') || 1 !== preg_match('/^(.+)@([^@]+)/', $value, $matches)) {
            $this->messages[] = self::INVALID_FORMAT;
            return false;
        }

        $localPart = $matches[1] ?? '';
        $hostname  = $matches[2] ?? '';
        $ascii     = idn_to_ascii($hostname, DomainName::IDNA_OPTIONS, INTL_IDNA_VARIANT_UTS46);
        if (false !== $ascii) {
            $hostname = $ascii;
        }

        $valid = true;
        if (strlen($localPart) > 64 || strlen($hostname) > 255) {
            $this->messages[] = self::LENGTH_EXCEEDED;
            $valid            = false;
        }

        $hostnameValidator = HostnameValidator::forEmailAddress();
        if (! $hostnameValidator->isValid($hostname)) {
            $this->messages[] = sprintf("'%s' is not a valid hostname for the email address", $hostname);
            $this->messages   = [...$this->messages, ...$hostnameValidator->getMessages()];
            $valid            = false;
        }

        if (! self::isValidLocalPart($localPart)) {
            $this->messages[] = sprintf("'%s' can not be matched against dot-atom format", $localPart);
            $this->messages[] = sprintf("'%s' can not be matched against quoted-string format", $localPart);
            $this->messages[] = sprintf("'%s' is not a valid local part for the email address", $localPart);
            $valid            = false;
        }

        return $valid;
    }

    /**
     * @return list<string>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Accepts a dot-atom, also extended with UTF-8 (RFC 6532), or a
     * quoted-string (RFC 5321 section 4.1.2).
     */
    private static function isValidLocalPart(string $localPart): bool
    {
        if (1 === preg_match(self::DOT_ATOM, $localPart)) {
            return true;
        }

        return 1 === preg_match('/^"([\x20-\x21\x23-\x5b\x5d-\x7e]|\x5c[\x20-\x7e])*"$/', $localPart);
    }
}
