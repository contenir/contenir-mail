<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;

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
 */
final class AddressListCodec
{
    private function __construct() {}

    /**
     * Parse an address-list header value, including groups ("Team: a@b, c@d;"),
     * quoted display names, comments and RFC 2047 encoded names.
     */
    public static function decode(string $value): AddressList
    {
        $addresses = [];
        foreach (ListParser::parse(self::flattenGroups(self::unfold($value))) as $entry) {
            $comments = self::getComments($entry);
            $entry    = trim(self::stripComments($entry));
            if ('' !== $entry) {
                $addresses[] = self::decodeAddress($entry, '' === $comments ? null : $comments);
            }
        }

        return new AddressList(...$addresses);
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
     * Replace each RFC 5322 group ("name: a@b, c@d;") with its member list.
     *
     * Quoted strings are matched first and kept as they are, so a quoted
     * display name containing ":" or ";" is never read as group syntax.
     */
    private static function flattenGroups(string $value): string
    {
        $quoted = '"(?:\\\\.|[^"\\\\])*"';

        return (string) preg_replace_callback(
            "/{$quoted}|[^:\";,]+:(?<members>(?:{$quoted}|[^;\"])*);/",
            /** @param array<array-key, string> $matches */
            static function (array $matches): string {
                $members = $matches['members'] ?? null;

                return null === $members ? $matches[0] ?? '' : "{$members},";
            },
            $value,
        );
    }

    /**
     * The comments in a value, such as "(work)", joined with ", ".
     */
    private static function getComments(string $value): string
    {
        $matches = [];
        preg_match_all('/\\(((?:\\\\.|[^\\\\)])+)\\)/', $value, $matches);

        return implode(', ', $matches[1] ?? []);
    }

    private static function stripComments(string $value): string
    {
        return (string) preg_replace('/\\((\\\\.|[^\\\\)])+\\)/', replacement: '', subject: $value);
    }
}
