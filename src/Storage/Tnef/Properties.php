<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Tnef;

/**
 * MAPI properties read from a TNEF attribute, by property id.
 *
 * @internal Returned by MapiProperties::read().
 */
final readonly class Properties
{
    /**
     * @param array<int, array{int, string}> $values Each property's type and raw value, by property id.
     */
    public function __construct(
        public array $values = [],
    ) {}

    /**
     * A text property as UTF-8, up to its first NUL; null when it is missing or not text.
     *
     * @param string $charset The charset of 8-bit text: the message's code page.
     */
    public function text(int $id, string $charset): ?string
    {
        [$type, $value] = $this->values[$id] ?? [null, ''];

        return match ($type) {
            MapiProperties::TYPE_UNICODE => Text::utf8($value, 'UTF-16LE'),
            MapiProperties::TYPE_STRING8 => Text::utf8($value, $charset),
            default                      => null,
        };
    }

    /**
     * A binary property; null when it is missing or not binary.
     */
    public function binary(int $id): ?string
    {
        [$type, $value] = $this->values[$id] ?? [null, ''];

        return MapiProperties::TYPE_BINARY === $type ? $value : null;
    }
}
