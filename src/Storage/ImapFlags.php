<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use function preg_match;
use function sprintf;

/**
 * Flags as IMAP writes them, checked so none can add to a command.
 *
 * @internal Used by Imap.
 */
final class ImapFlags
{
    /** IMAP SEARCH keys of the flags that have one */
    private const array SEARCH_KEYS = [
        '\Seen'     => 'SEEN',
        '\Answered' => 'ANSWERED',
        '\Flagged'  => 'FLAGGED',
        '\Deleted'  => 'DELETED',
        '\Draft'    => 'DRAFT',
        '\Recent'   => 'RECENT',
    ];

    /** A system flag or keyword: an atom, optionally after "\" (RFC 3501, section 9) */
    private const string FLAG = '/^\\\\?[^\x00-\x20\x7F(){%*"\\\\\]]+$/D';

    /**
     * Flags to store: none may be Recent, which only the server sets.
     *
     * @param iterable<Flag|string> $flags
     * @return list<string>
     * @throws Exception\InvalidArgumentException When a flag is Recent or not a valid IMAP flag.
     */
    public static function toStore(iterable $flags): array
    {
        $names = [];
        foreach ($flags as $flag) {
            $name = self::name($flag);
            if (Flag::Recent->value === $name) {
                throw new Exception\InvalidArgumentException('The Recent flag may not be set');
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * SEARCH criteria matching messages with every flag; "ALL" for none.
     *
     * @param array<array-key, Flag|string> $flags
     * @param callable(string): string $escape Quotes a keyword for the protocol.
     * @return list<string>
     * @throws Exception\InvalidArgumentException When a flag is not a valid IMAP flag.
     */
    public static function toSearch(array $flags, callable $escape): array
    {
        $criteria = [];
        foreach ($flags as $flag) {
            $name = self::name($flag);
            $key  = self::SEARCH_KEYS[$name] ?? null;
            if (null !== $key) {
                $criteria[] = $key;
                continue;
            }

            $criteria[] = 'KEYWORD';
            $criteria[] = $escape($name);
        }

        return [] === $criteria ? ['ALL'] : $criteria;
    }

    /**
     * @throws Exception\InvalidArgumentException When a keyword holds characters IMAP does not allow in an atom.
     */
    private static function name(Flag|string $flag): string
    {
        $flag = Flag::normalize($flag);
        if ($flag instanceof Flag) {
            return $flag->value;
        }

        if (1 !== preg_match(self::FLAG, $flag)) {
            throw new Exception\InvalidArgumentException(sprintf('"%s" is not a valid IMAP flag', $flag));
        }

        return $flag;
    }
}
