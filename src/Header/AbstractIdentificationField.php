<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Headers;
use Override;

use function array_map;
use function implode;
use function preg_split;
use function sprintf;
use function strtolower;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Base for headers holding a list of message IDs: In-Reply-To and References.
 *
 * @api
 */
abstract readonly class AbstractIdentificationField implements HeaderInterface
{
    /** The canonical header name */
    protected const string FIELD_NAME = '';

    /** @var list<string> without angle brackets */
    private array $ids;

    /**
     * @throws Exception\InvalidArgumentException When an ID is empty, too long or contains invalid characters.
     */
    final public function __construct(string ...$ids)
    {
        $checked = [];
        foreach ($ids as $id) {
            $checked[] = MessageId::check($id);
        }

        $this->ids = $checked;
    }

    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$name, $value] = GenericHeader::splitHeaderLine($headerLine);
        if (strtolower($name) !== strtolower(static::FIELD_NAME)) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Invalid header line for "%s" string',
                static::FIELD_NAME,
            ));
        }

        $ids = preg_split(
            '/[\s,]+|(?<=>)(?=<)/',
            HeaderWrap::mimeDecodeValue($value),
            flags: PREG_SPLIT_NO_EMPTY,
        );

        return new static(...false === $ids ? [] : $ids);
    }

    /**
     * @return list<string> without angle brackets
     */
    public function getIds(): array
    {
        return $this->ids;
    }

    #[Override]
    public function getFieldName(): string
    {
        return static::FIELD_NAME;
    }

    #[Override]
    public function getFieldValue(): string
    {
        return implode(' ', array_map(static fn(string $id): string => "<{$id}>", $this->ids));
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        return implode(Headers::FOLDING, array_map(static fn(string $id): string => "<{$id}>", $this->ids));
    }

    #[Override]
    public function toString(): string
    {
        return sprintf('%s: %s', static::FIELD_NAME, $this->getEncodedFieldValue());
    }
}
