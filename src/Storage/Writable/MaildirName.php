<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Writable;

use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\Flag;
use Random\RandomException;

use function array_keys;
use function array_values;
use function bin2hex;
use function getmypid;
use function implode;
use function ksort;
use function microtime;
use function php_uname;
use function preg_match;
use function random_bytes;
use function sprintf;
use function str_replace;
use function substr;

use const SORT_STRING;

/**
 * Names of the message files Writable\Maildir creates.
 *
 * @internal Used by Writable\Maildir and MaildirDelivery.
 */
final class MaildirName
{
    /**
     * A Maildir unique name: time, microseconds, process, random bits and host
     * (as the Maildir and Maildir++ specifications describe),
     * with "\\", "/", ":" and "," in the host escaped as octal, so the host
     * can neither add a path nor be mistaken for the info or size fields.
     *
     * @param string|null $host This machine's name when null.
     * @throws Exception\RuntimeException When the system has no source of randomness.
     */
    public static function unique(?string $host = null): string
    {
        try {
            $random = bin2hex(random_bytes(8));

            // @codeCoverageIgnoreStart
            // Unreachable on supported systems, which always have a source of randomness
        } catch (RandomException $e) {
            throw new Exception\RuntimeException('No source of randomness for a unique name', 0, $e);
        }

        // @codeCoverageIgnoreEnd

        $time = sprintf('%.6F', microtime(as_float: true));
        $host = str_replace(
            search: ['\\', '/', ':', ','],
            replace: ['\\134', '\\057', '\\072', '\\054'],
            subject: $host ?? php_uname('n'),
        );

        return sprintf(
            '%s.M%sP%dQ%s.%s',
            substr($time, offset: 0, length: -7),
            substr($time, -6),
            (int) getmypid(),
            $random,
            $host,
        );
    }

    /**
     * The Maildir info for flags, "2," and their letters in ASCII order, and the flags as stored.
     *
     * @param iterable<Flag|string> $flags
     * @return array{string, list<Flag|string>}
     * @throws Exception\InvalidArgumentException When a flag is Recent, or has no Maildir letter.
     */
    public static function info(iterable $flags): array
    {
        $letters = [];
        foreach ($flags as $flag) {
            $flag = Flag::normalise($flag);
            if (Flag::Recent === $flag) {
                throw new Exception\InvalidArgumentException('The Recent flag may not be set');
            }

            $letters[$flag instanceof Flag ? (string) $flag->maildirLetter() : self::keyword($flag)] = $flag;
        }

        ksort($letters, SORT_STRING);

        return ['2,' . implode('', array_keys($letters)), array_values($letters)];
    }

    /**
     * A Maildir keyword letter, "a" to "z". Every case but Recent has a letter of its own.
     *
     * @throws Exception\InvalidArgumentException When the flag is not a keyword letter.
     */
    private static function keyword(string $flag): string
    {
        if (1 !== preg_match('/^[a-z]$/D', $flag)) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Unknown flag %s; Maildir keeps the common flags and keywords "a" to "z"',
                $flag,
            ));
        }

        return $flag;
    }
}
