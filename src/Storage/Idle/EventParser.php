<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Idle;

use Contenir\Mail\Storage\Flag;

use function array_chunk;
use function ctype_digit;
use function is_array;
use function is_scalar;
use function is_string;
use function strtoupper;

/**
 * Reads the event an untagged IMAP response tells of, from its tokens.
 *
 * @internal Used by Storage\Imap::idle().
 *
 * @mago-expect analysis:mixed-assignment Response tokens are strings and lists nested to any depth.
 */
final class EventParser
{
    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

    /**
     * The event, or null for a response that tells of none, such as "* OK Still here".
     *
     * @param array<mixed> $tokens An untagged response after "* ", as Protocol\Imap::idle() yields it.
     */
    public static function fromResponse(array $tokens): ?EventInterface
    {
        $number = $tokens[0] ?? null;
        $name   = $tokens[1] ?? null;
        if (! is_string($number) || ! ctype_digit($number) || ! is_string($name)) {
            return null;
        }

        return match (strtoupper($name)) {
            'EXISTS'  => new Exists((int) $number),
            'EXPUNGE' => new Expunge((int) $number),
            'RECENT'  => new Recent((int) $number),
            'FETCH'   => self::flagsChanged((int) $number, $tokens[2] ?? null),
            default   => null,
        };
    }

    /**
     * The FLAGS item of a FETCH response, which servers send when another client changes flags.
     */
    private static function flagsChanged(int $number, mixed $items): ?FlagsChanged
    {
        foreach (array_chunk(is_array($items) ? $items : [], length: 2) as $pair) {
            $name  = $pair[0] ?? null;
            $value = $pair[1] ?? null;
            if (! is_string($name) || 'FLAGS' !== strtoupper($name) || ! is_array($value)) {
                continue;
            }

            $flags = [];
            foreach ($value as $flag) {
                $flags[] = Flag::fromImap(is_scalar($flag) ? (string) $flag : '');
            }

            return new FlagsChanged($number, $flags);
        }

        return null;
    }
}
