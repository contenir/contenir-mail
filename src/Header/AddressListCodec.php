<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Address;
use Contenir\Mail\AddressGroup;
use Contenir\Mail\AddressList;
use Contenir\Mail\Exception\InvalidArgumentException as MailInvalidArgumentException;

use function count;
use function implode;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function trim;

/**
 * Reads RFC 5322 address lists.
 *
 * @internal Used by the address-list headers.
 *
 * @mago-expect lint:cyclomatic-complexity Address lists with groups, quoted names, comments, source routes and encoded words.
 */
final class AddressListCodec
{
    /** A comment such as "(work)", skipping quoted strings, in which parentheses are only text */
    private const string COMMENT = '/"(?:\\\\.|[^"\\\\])*+"(*SKIP)(*FAIL)|\\(((?:\\\\.|[^\\\\)])+)\\)/';

    private function __construct() {}

    /**
     * Parse an address-list header value, including groups ("Team: a@b, c@d;"),
     * quoted display names, comments and RFC 2047 encoded names.
     */
    public static function decode(string $value): AddressList
    {
        $addresses = [];
        foreach (self::decodeEntries($value) as $entry) {
            foreach ($entry instanceof AddressGroup ? $entry->getAddresses() : [$entry] as $address) {
                $addresses[] = $address;
            }
        }

        return new AddressList(...$addresses);
    }

    /**
     * Parse an address-list header value into its addresses and groups, in order.
     *
     * Each group is swapped for a placeholder holding a NUL, which no header value can
     * contain, before the list is split, then decoded on its own.
     *
     * @return list<Address|AddressGroup>
     * @throws MailInvalidArgumentException When an address or group name is invalid.
     */
    public static function decodeEntries(string $value): array
    {
        $quoted = '"(?:\\\\.|[^"\\\\])*"';
        /** @var array<string, AddressGroup> $groups */
        $groups = [];
        $rest   = (string) preg_replace_callback(
            "/{$quoted}|(?<name>[^:\";,<>]+):(?<members>(?:{$quoted}|[^;\"])*);/",
            /** @param array<array-key, string> $matches */
            static function (array $matches) use (&$groups): string {
                $members = $matches['members'] ?? null;
                if (null === $members) {
                    return $matches[0] ?? '';
                }

                $index                = count($groups);
                $placeholder          = "\0{$index}";
                $groups[$placeholder] = new AddressGroup(
                    self::decodePhrase($matches['name'] ?? ''),
                    new AddressList(...self::decodeAddresses($members)),
                );

                return "{$placeholder},";
            },
            self::unfold($value),
        );

        $entries = [];
        foreach (ListParser::parse($rest) as $item) {
            $group = $groups[$item] ?? null;
            if (null !== $group) {
                $entries[] = $group;
                continue;
            }

            foreach (self::decodeAddresses($item) as $address) {
                $entries[] = $address;
            }
        }

        return $entries;
    }

    /**
     * @return list<Address>
     * @throws MailInvalidArgumentException When an address is invalid.
     */
    private static function decodeAddresses(string $value): array
    {
        $addresses = [];
        foreach (ListParser::parse($value) as $entry) {
            $comments = self::getComments($entry);
            $entry    = trim(self::stripComments($entry));
            if ('' !== $entry) {
                $addresses[] = self::decodeAddress($entry, '' === $comments ? null : $comments);
            }
        }

        return $addresses;
    }

    /**
     * Join folded lines (RFC 5322, section 2.2.3), so no line break is left in a name or comment.
     *
     * The value has passed HeaderValue::isValidUtf8(), so every CR and LF in it is part of a fold.
     */
    private static function unfold(string $value): string
    {
        return (string) preg_replace('/\r\n[ \t]/', replacement: ' ', subject: $value);
    }

    /**
     * Split one entry into its display name and address before decoding the
     * name, so text inside an encoded word is never read as syntax.
     */
    private static function decodeAddress(string $entry, ?string $comment): Address
    {
        $matches = [];
        if (1 !== preg_match('/^(?<phrase>.*)<(?<email>[^<>]+)>$/', $entry, $matches)) {
            // Outlook sometimes wraps addresses in single quotes, which is not valid
            return new Address(trim($entry, characters: " \t'"), comment: $comment);
        }

        $email = self::withoutSourceRoute(trim($matches['email'] ?? '', characters: " \t'"));

        return new Address($email, self::decodePhrase($matches['phrase'] ?? ''), $comment);
    }

    /**
     * The address without an obsolete source route ("@relay.example:" before it), which
     * RFC 5322 section 4.4 says to ignore.
     */
    private static function withoutSourceRoute(string $email): string
    {
        return (string) preg_replace('/^(?:[\s,]*@[^\s,:@<>]+)+[\s,]*:\s*/', replacement: '', subject: $email);
    }

    /**
     * Remove quoted-string syntax from a display name, then decode its encoded words.
     */
    private static function decodePhrase(string $phrase): string
    {
        $unquoted = (string) preg_replace(
            ['/(?<!\\\\)"/', '/\\\\([\x01-\x09\x0b\x0c\x0e-\x7f])/'],
            ['', '$1'],
            $phrase,
        );

        return HeaderWrap::mimeDecodeValue($unquoted);
    }

    /**
     * The comments in a value, such as "(work)", joined with ", ".
     */
    private static function getComments(string $value): string
    {
        $matches = [];
        preg_match_all(self::COMMENT, $value, $matches);

        return implode(', ', $matches[1] ?? []);
    }

    private static function stripComments(string $value): string
    {
        return (string) preg_replace(self::COMMENT, replacement: '', subject: $value);
    }
}
