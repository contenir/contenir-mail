<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Tnef;

use Contenir\Mail\Storage\Exception\RuntimeException;

use function in_array;
use function intdiv;
use function sprintf;

/**
 * Reads the MAPI properties of an attMsgProps or attAttachment attribute (MS-OXTNEF, section 2.1.3.4).
 *
 * Every property is read, so the next one starts in the right place, but
 * only single-valued properties with a numeric tag are kept: named
 * properties are numbered differently by each sender.
 *
 * Counts are checked against the bytes remaining before they are used:
 * every value takes at least four bytes, so a count larger than a quarter
 * of what is left cannot be honest.
 *
 * @internal Used by the TNEF reader.
 *
 * @mago-expect lint:cyclomatic-complexity Every count, length, type and name kind is checked against the bytes left.
 */
final class MapiProperties
{
    /** PtypString8: text in the message's code page */
    public const int TYPE_STRING8 = 0x001E;

    /** PtypString: UTF-16LE text */
    public const int TYPE_UNICODE = 0x001F;

    /** PtypBinary */
    public const int TYPE_BINARY = 0x0102;

    /** Set on the type of a multi-valued property */
    private const int MULTIPLE = 0x1000;

    /** Property ids from this one up are named properties */
    private const int FIRST_NAMED_ID = 0x8000;

    /**
     * The variable-length types: PtypObject, PtypString8, PtypString and PtypBinary
     *
     * @var list<int>
     */
    private const array VARIABLE_TYPES = [0x000D, self::TYPE_STRING8, self::TYPE_UNICODE, self::TYPE_BINARY];

    /**
     * Bytes each fixed-length type takes, padded to four bytes as TNEF writes them.
     *
     * @var array<int, int>
     */
    private const array FIXED_SIZES = [
        0x0002 => 4,
        0x0003 => 4,
        0x0004 => 4,
        0x0005 => 8,
        0x0006 => 8,
        0x0007 => 8,
        0x000A => 4,
        0x000B => 4,
        0x0014 => 8,
        0x0040 => 8,
        0x0048 => 16,
    ];

    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

    /**
     * The single-valued, numerically tagged properties.
     *
     * @throws RuntimeException When the properties are malformed or run past the end of the data.
     */
    public static function read(string $data): Properties
    {
        $input      = new ByteReader($data);
        $count      = self::count($input);
        $properties = [];
        for ($index = 0; $index < $count; $index++) {
            $type = $input->uint16();
            $id   = $input->uint16();
            if ($id >= self::FIRST_NAMED_ID) {
                self::skipName($input);
            }

            $value = self::value($input, $type);
            if ($id < self::FIRST_NAMED_ID && null !== $value) {
                $properties[$id] = [$type, $value];
            }
        }

        return new Properties($properties);
    }

    /**
     * A count of values that follow, refused when the bytes remaining cannot hold that many.
     *
     * @throws RuntimeException When the count is too large or the data ends first.
     */
    private static function count(ByteReader $input): int
    {
        $count = $input->uint32();
        if ($count > intdiv($input->remaining(), num2: 4)) {
            throw new RuntimeException(sprintf(
                'A MAPI count of %d is more than the %d bytes left can hold',
                $count,
                $input->remaining(),
            ));
        }

        return $count;
    }

    /**
     * Skip the GUID and the number or name that identify a named property.
     *
     * @throws RuntimeException When the name kind is unknown or the data ends first.
     */
    private static function skipName(ByteReader $input): void
    {
        $input->bytes(16);
        $kind = $input->uint32();
        if (0 === $kind) {
            $input->uint32();

            return;
        }

        if (1 !== $kind) {
            throw new RuntimeException(sprintf('A MAPI named property has the unknown kind %d', $kind));
        }

        $input->bytes(self::padded($input->uint32()));
    }

    /**
     * Read a property's values, returning the value of a single-valued property.
     *
     * @return string|null Null for a multi-valued property, or one without values.
     * @throws RuntimeException When the type is unknown or the data ends first.
     */
    private static function value(ByteReader $input, int $type): ?string
    {
        $base     = $type & ~self::MULTIPLE;
        $variable = in_array($base, self::VARIABLE_TYPES, strict: true);
        $size     = self::FIXED_SIZES[$base] ?? null;
        if (! $variable && null === $size) {
            throw new RuntimeException(sprintf('A MAPI property has the unknown type 0x%04X', $base));
        }

        $count  = $variable || $base !== $type ? self::count($input) : 1;
        $values = [];
        for ($index = 0; $index < $count; $index++) {
            $values[] = null === $size ? self::variableValue($input) : $input->bytes($size);
        }

        return $base === $type ? $values[0] ?? null : null;
    }

    /**
     * A length-prefixed value, without the padding that follows it.
     *
     * @throws RuntimeException When the data ends first.
     */
    private static function variableValue(ByteReader $input): string
    {
        $length = $input->uint32();
        $value  = $input->bytes($length);
        $input->bytes(self::padded($length) - $length);

        return $value;
    }

    /**
     * A length rounded up to a multiple of four.
     */
    private static function padded(int $length): int
    {
        return ($length + 3) & ~3;
    }
}
