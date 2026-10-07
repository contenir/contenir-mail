<?php

declare(strict_types=1);

namespace Contenir\Mail\Validator;

use function filter_var;
use function is_string;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function substr;

use const FILTER_VALIDATE_IP;

/**
 * Validates host names the way laminas-validator 2's Hostname validator did
 * for the two configurations contenir-mail used.
 *
 * - forEmailAddress(): DNS and local network names (Hostname::ALLOW_DNS |
 *   Hostname::ALLOW_LOCAL); IP addresses are rejected.
 * - forConnection(): additionally IP addresses and RFC 3986 URI host names
 *   (Hostname::ALLOW_ALL).
 *
 * @internal
 */
final class HostnameValidator
{
    public const string INVALID_TYPE = 'Invalid type given. String expected';

    public const string IP_ADDRESS_NOT_ALLOWED = 'The input appears to be an IP address, but IP addresses are not allowed';

    public const string INVALID_HOSTNAME = 'The input does not match the expected structure for a DNS hostname';

    private const string URI_HOST = "/^([a-zA-Z0-9-._~!$&'()*+,;=]|%[[:xdigit:]]{2}){1,254}$/";

    /** @var list<string> */
    private array $messages = [];

    private function __construct(
        private readonly bool $allowIpAndUri,
    ) {}

    public static function forEmailAddress(): self
    {
        return new self(allowIpAndUri: false);
    }

    public static function forConnection(): self
    {
        return new self(allowIpAndUri: true);
    }

    public function isValid(mixed $value): bool
    {
        $this->messages = [];

        if (! is_string($value)) {
            $this->messages[] = self::INVALID_TYPE;
            return false;
        }

        if (self::isIpAddress($value)) {
            if ($this->allowIpAndUri) {
                return true;
            }

            $this->messages[] = self::IP_ADDRESS_NOT_ALLOWED;
            return false;
        }

        if ($this->isValidName(self::withoutTrailingDot($value))) {
            return true;
        }

        $this->messages[] = self::INVALID_HOSTNAME;
        return false;
    }

    /**
     * @return list<string>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    private function isValidName(string $value): bool
    {
        if (str_ends_with($value, '.')) {
            return false;
        }

        if ($this->allowIpAndUri && 1 === preg_match(self::URI_HOST, $value)) {
            return true;
        }

        return DomainName::isLocalOrDnsName($value);
    }

    private static function isIpAddress(string $value): bool
    {
        $looksLikeIp =
            1 === preg_match('/^[0-9.]*$/', $value) && str_contains($value, '.')
            || 1 === preg_match('/^[0-9a-f:.]*$/i', $value) && str_contains($value, ':');

        return $looksLikeIp && false !== filter_var($value, FILTER_VALIDATE_IP);
    }

    private static function withoutTrailingDot(string $value): string
    {
        return str_ends_with($value, '.') ? substr($value, offset: 0, length: -1) : $value;
    }
}
