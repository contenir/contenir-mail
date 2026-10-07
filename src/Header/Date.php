<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use DateTimeImmutable;
use DateTimeInterface;
use Exception as PhpException;
use Override;

use function array_filter;
use function date_parse;
use function preg_replace;
use function strtolower;
use function trim;

/**
 * The origination date of a message (RFC 5322, section 3.6.1).
 */
final readonly class Date implements HeaderInterface
{
    private DateTimeImmutable $date;

    public function __construct(DateTimeInterface $date)
    {
        $this->date = DateTimeImmutable::createFromInterface($date);
    }

    /**
     * Accepts RFC 5322 dates, including the obsolete forms and trailing
     * comments such as "(CEST)" that real mail carries.
     *
     * @throws Exception\InvalidArgumentException When the value is not a date.
     */
    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$name, $value] = GenericHeader::splitHeaderLine($headerLine);
        if ('date' !== strtolower($name)) {
            throw new Exception\InvalidArgumentException('Invalid header line for Date string');
        }

        $value = trim((string) preg_replace(
            '/\s*\([^)]*\)\s*$/',
            replacement: '',
            subject: HeaderWrap::mimeDecodeValue($value),
        ));

        if (! self::isAbsoluteDate($value)) {
            throw new Exception\InvalidArgumentException("Invalid Date header value \"{$value}\"");
        }

        try {
            return new self(new DateTimeImmutable($value));

            // @codeCoverageIgnoreStart
            // Unreachable: isAbsoluteDate() has already parsed the value with the same parser
        } catch (PhpException $e) {
            throw new Exception\InvalidArgumentException("Invalid Date header value \"{$value}\"", 0, $e);
        }

        // @codeCoverageIgnoreEnd
    }

    /**
     * A full calendar date with no relative parts, so "", "now" or "tomorrow" are refused.
     */
    private static function isAbsoluteDate(string $value): bool
    {
        /** @var array{error_count: int, year: int|false, month: int|false, day: int|false, relative?: array<string, int>} $parsed */
        $parsed = date_parse($value);

        // A day of the week alone shows up as a relative part with no offset
        $relative = $parsed['relative'] ?? [];
        unset($relative['weekday']);

        return (
            0 === $parsed['error_count']
                && false !== $parsed['year']
                && false !== $parsed['month']
                && false !== $parsed['day']
                && [] === array_filter($relative)
        );
    }

    public function getDate(): DateTimeImmutable
    {
        return $this->date;
    }

    #[Override]
    public function getFieldName(): string
    {
        return 'Date';
    }

    #[Override]
    public function getFieldValue(): string
    {
        return $this->date->format(DateTimeInterface::RFC2822);
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        return $this->getFieldValue();
    }

    #[Override]
    public function toString(): string
    {
        return "Date: {$this->getFieldValue()}";
    }
}
