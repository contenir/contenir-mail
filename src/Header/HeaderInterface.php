<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

/**
 * A single e-mail header field.
 *
 * Headers are immutable. Values are held decoded as UTF-8; the encoded form
 * is produced on output, as printable US-ASCII when possible and as RFC 2047
 * encoded words otherwise.
 *
 * @api
 */
interface HeaderInterface
{
    /**
     * Parse a complete header line, such as "Subject: Hello".
     *
     * @throws Exception\InvalidArgumentException When the line is not a valid header of this type.
     */
    public static function fromString(string $headerLine): static;

    public function getFieldName(): string;

    /**
     * The decoded value, as a reader would see it.
     */
    public function getFieldValue(): string;

    /**
     * The value as it is written on the wire: folded, and encoded where needed.
     */
    public function getEncodedFieldValue(): string;

    /**
     * The complete header line, "Name: encoded value", without a trailing line break.
     */
    public function toString(): string;
}
