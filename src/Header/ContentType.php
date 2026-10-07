<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Override;

use function explode;
use function in_array;
use function preg_match;
use function sprintf;
use function strtolower;
use function trim;

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
        if (1 !== preg_match('/^[a-z-]+\/[a-z0-9.+-]+$/i', $type)) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Content-Type expects a value in the format "type/subtype"; received "%s"',
                $type,
            ));
        }

        $normalised = [];
        foreach ($parameters as $name => $value) {
            $name = strtolower(trim($name));
            if (! HeaderValue::isValid($name)) {
                throw new Exception\InvalidArgumentException('Invalid content-type parameter name detected');
            }

            if (! HeaderWrap::canBeEncoded($value)) {
                throw new Exception\InvalidArgumentException(
                    'Parameter value must be composed of printable US-ASCII or UTF-8 characters.',
                );
            }

            $normalised[$name] = $value;
        }

        $this->type       = $type;
        $this->parameters = $normalised;
    }

    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$name, $value] = GenericHeader::splitHeaderLine($headerLine);
        if (! in_array(strtolower($name), ['contenttype', 'content_type', 'content-type'], strict: true)) {
            throw new Exception\InvalidArgumentException('Invalid header line for Content-Type string');
        }

        $value = HeaderWrap::mimeDecodeValue($value);
        $parts = explode(';', $value, limit: 2);

        $parameters = [];
        foreach (HeaderParameters::parse($parts[1] ?? '') as [$parameterName, $parameterValue]) {
            $parameters[$parameterName] = $parameterValue;
        }

        return new self(trim($parts[0]), $parameters);
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

    public function withType(string $type): self
    {
        return new self($type, $this->parameters);
    }

    public function withParameter(string $name, string $value): self
    {
        return new self($this->type, [...$this->parameters, $name => $value]);
    }

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

    #[Override]
    public function getFieldValue(): string
    {
        return HeaderParameters::join($this->type, $this->parameters);
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        return HeaderParameters::joinEncoded('Content-Type', $this->type, $this->parameters);
    }

    #[Override]
    public function toString(): string
    {
        return "Content-Type: {$this->getEncodedFieldValue()}";
    }
}
