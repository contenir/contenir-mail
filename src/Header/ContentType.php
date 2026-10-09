<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use NoDiscard;
use Override;

use function in_array;
use function sprintf;
use function strpos;
use function strtolower;
use function substr;

/**
 * The media type of a message or part, with its parameters (RFC 2045, section 5).
 *
 */
final readonly class ContentType implements HeaderInterface
{
    private string $type;

    /** @var array<string, string> keyed by lower-cased parameter name */
    private array $parameters;

    /**
     * @param array<string, string> $parameters
     * @throws Exception\InvalidArgumentException When the type is not "type/subtype" or a parameter is invalid.
     */
    public function __construct(string $type, array $parameters = [])
    {
        $slash = strpos($type, needle: '/');
        if (
            false === $slash
            || ! MimeParameters::isToken(substr($type, offset: 0, length: $slash))
            || ! MimeParameters::isToken(substr($type, $slash + 1))
        ) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Content-Type expects a value in the format "type/subtype"; received "%s"',
                $type,
            ));
        }

        $normalized = [];
        foreach ($parameters as $name => $value) {
            $normalized[MimeParameters::name($name, 'content-type')] = MimeParameters::value($value);
        }

        $this->type       = $type;
        $this->parameters = $normalized;
    }

    /**
     * Parameters are read from the value as written; encoded words and RFC
     * 2231 extended values in them are decoded once the structure is read.
     */
    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$name, $value] = GenericHeader::splitHeaderLine($headerLine);
        if (! in_array(strtolower($name), ['contenttype', 'content_type', 'content-type'], strict: true)) {
            throw new Exception\InvalidArgumentException('Invalid header line for Content-Type string');
        }

        [$type, $parameters] = MimeParameterParser::parse($value, $headerLine, 'Content-Type');

        return new self($type, $parameters);
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, string>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getParameter(string $name): ?string
    {
        return $this->parameters[strtolower($name)] ?? null;
    }

    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function withType(string $type): self
    {
        return new self($type, $this->parameters);
    }

    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function withParameter(string $name, string $value): self
    {
        return new self($this->type, [...$this->parameters, $name => $value]);
    }

    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function withoutParameter(string $name): self
    {
        $parameters = $this->parameters;
        unset($parameters[strtolower($name)]);

        return new self($this->type, $parameters);
    }

    #[Override]
    public function getFieldName(): string
    {
        return 'Content-Type';
    }

    /**
     * The type and its parameters on one line, as a reader would see them.
     */
    #[Override]
    public function getFieldValue(): string
    {
        $value = $this->type;
        foreach ($this->parameters as $name => $parameter) {
            $value .= sprintf('; %s="%s"', $name, $parameter);
        }

        return $value;
    }

    /**
     * Parameters on the first line while they fit, then each parameter, or
     * continuation section, on its own folded line.
     */
    #[Override]
    public function getEncodedFieldValue(): string
    {
        $value = $this->type;
        foreach ($this->parameters as $name => $parameter) {
            $value = MimeParameters::append('Content-Type', $value, $name, $parameter);
        }

        return $value;
    }

    #[Override]
    public function toString(): string
    {
        return "Content-Type: {$this->getEncodedFieldValue()}";
    }
}
