<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Imap;

use function array_filter;
use function array_slice;
use function count;
use function implode;
use function is_string;
use function preg_match;

/**
 * The UIDs a server gave messages it stored, from the APPENDUID or COPYUID
 * response code (RFC 4315, UIDPLUS; part of IMAP4rev2).
 *
 * @api
 */
final readonly class UidPlus
{
    /** The most UIDs a response code may expand to */
    public const int MAX_UIDS = 1_000_000;

    /** The largest UID or UIDVALIDITY, a 32-bit nz-number (RFC 3501) */
    public const int MAX_UID = 4_294_967_295;

    /** RFC 4315 uid-set: UIDs and ranges of them joined by ",", never "*" */
    private const string UID_SET = '[1-9]\d{0,9}(?::[1-9]\d{0,9})?(?:,[1-9]\d{0,9}(?::[1-9]\d{0,9})?)*+';

    /** "[APPENDUID uidvalidity uid-set]" or "[COPYUID uidvalidity uid-set uid-set]" at the start of the text */
    private const string CODE =
        '/^\[(?:APPENDUID ([1-9]\d{0,9})|COPYUID ([1-9]\d{0,9}) (' . self::UID_SET . ')) (' . self::UID_SET . ')\]/i';

    /**
     * @param int $uidValidity The UIDVALIDITY of the folder the messages were stored in.
     * @param list<int> $sourceUids The UIDs of the copied messages, in the order of $destinationUids; empty for APPEND.
     * @param list<int> $destinationUids The UIDs of the stored messages.
     */
    public function __construct(
        public int $uidValidity,
        public array $sourceUids,
        public array $destinationUids,
    ) {}

    /**
     * The UID of the stored message, when there is exactly one.
     */
    public function uid(): ?int
    {
        return 1 === count($this->destinationUids) ? $this->destinationUids[0] : null;
    }

    /**
     * The UIDs in the tagged reply to APPEND or COPY, such as
     * ["OK", "[APPENDUID", "38505", "3955]", "done"].
     *
     * A reply without the code, or with one that is malformed, out of range, larger than
     * MAX_UIDS or whose sets differ in size, gives null: the command still succeeded.
     *
     * @param array<mixed> $tokens The decoded tokens of the tagged reply.
     *
     * @internal Used by Contenir\Mail\Protocol\Imap.
     */
    public static function fromTaggedReply(array $tokens): ?self
    {
        $words   = array_slice($tokens, offset: 1, length: 4);
        $strings = array_filter($words, is_string(...));
        $matches = [];
        if (count($strings) !== count($words) || 1 !== preg_match(self::CODE, implode(' ', $strings), $matches)) {
            return null;
        }

        return self::create(($matches[1] ?? '') . ($matches[2] ?? ''), $matches[3] ?? '', $matches[4] ?? '');
    }

    /**
     * @param string $source A uid-set, or empty for APPENDUID.
     */
    private static function create(string $uidValidity, string $source, string $destination): ?self
    {
        $validity        = (int) $uidValidity;
        $sourceUids      = '' === $source ? [] : UidSet::expand($source);
        $destinationUids = UidSet::expand($destination);
        if (
            $validity > self::MAX_UID
            || null === $sourceUids
            || null === $destinationUids
            || (
                [] !== $sourceUids
                && count($sourceUids) !== count($destinationUids)
            )
        ) {
            return null;
        }

        return new self($validity, $sourceUids, $destinationUids);
    }
}
