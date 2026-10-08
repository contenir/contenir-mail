<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Imap;

use function array_map;
use function count;
use function explode;
use function intval;
use function max;
use function min;
use function range;

/**
 * The UIDs of an RFC 4315 uid-set the server sent, bounded in value and number.
 *
 * @internal Used by Contenir\Mail\Protocol\Imap\UidMapping.
 */
final class UidSet
{
    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

    /**
     * The UIDs of a uid-set already matched against its grammar, each range in ascending order;
     * null when a UID is over UidMapping::MAX_UID or there are more than UidMapping::MAX_UIDS.
     *
     * @return list<int>|null
     */
    public static function expand(string $set): ?array
    {
        $uids = [];
        foreach (explode(',', $set) as $range) {
            $bounds = array_map(intval(...), explode(':', $range));
            $low    = min($bounds);
            $high   = max($bounds);
            if ($high > UidMapping::MAX_UID || (count($uids) + $high - $low + 1) > UidMapping::MAX_UIDS) {
                return null;
            }

            foreach (range($low, $high) as $uid) {
                $uids[] = $uid;
            }
        }

        return $uids;
    }
}
