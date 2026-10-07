<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Headers;
use Contenir\Mail\Mime\Mime;
use Override;

use function array_key_last;
use function count;
use function explode;
use function in_array;
use function sprintf;
use function strlen;
use function strtolower;
use function trim;

/**
 * How a part is presented, inline or as an attachment, with its parameters (RFC 2183).
 *
 * @mago-expect lint:too-many-methods The HeaderInterface methods plus typed accessors and with*() for each part.
 */
final readonly class ContentDisposition implements HeaderInterface
{
    /** Longest parameter line before RFC 2231 continuation splits it */
    public const int MAX_PARAMETER_LENGTH = 76;

    private string $disposition;

    /** @var array<string, string> keyed by lower-cased parameter name */
    private array $parameters;

    /**
     * @param array<string, string> $parameters
     * @throws Exception\InvalidArgumentException When a parameter name is invalid or too long.
     */
    public function __construct(string $disposition = 'inline', array $parameters = [])
    {
        $normalised = [];
        foreach ($parameters as $name => $value) {
            $name = strtolower($name);
            if (! HeaderValue::isValid($name)) {
                throw new Exception\InvalidArgumentException('Invalid content-disposition parameter name detected');
            }

            // 5 covers the quotes and equals sign of name="value", and the space and semicolon of folding
            if ((strlen($name) + 5) >= self::MAX_PARAMETER_LENGTH) {
                throw new Exception\InvalidArgumentException(
                    'Invalid content-disposition parameter name detected (too long)',
                );
            }

            $normalised[$name] = $value;
        }

        $this->disposition = strtolower($disposition);
        $this->parameters  = $normalised;
    }

    /**
     * Reassembles parameters split with RFC 2231 continuations (filename*0=, filename*1=).
     */
    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$name, $value] = GenericHeader::splitHeaderLine($headerLine);
        $names = ['contentdisposition', 'content_disposition', 'content-disposition'];
        if (! in_array(strtolower($name), $names, strict: true)) {
            throw new Exception\InvalidArgumentException('Invalid header line for Content-Disposition string');
        }

        $value      = HeaderWrap::mimeDecodeValue($value);
        $parts      = explode(';', $value, limit: 2);
        $parameters = HeaderParameters::parse($parts[1] ?? '');

        return new self(trim($parts[0]), ParameterContinuation::join($parameters, $headerLine));
    }

    public function getDisposition(): string
    {
        return $this->disposition;
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

    public function withDisposition(string $disposition): self
    {
        return new self($disposition, $this->parameters);
    }

    public function withParameter(string $name, string $value): self
    {
        return new self($this->disposition, [...$this->parameters, $name => $value]);
    }

    public function withoutParameter(string $name): self
    {
        $parameters = $this->parameters;
        unset($parameters[strtolower($name)]);

        return new self($this->disposition, $parameters);
    }

    #[Override]
    public function getFieldName(): string
    {
        return 'Content-Disposition';
    }

    #[Override]
    public function getFieldValue(): string
    {
        $result = $this->disposition;
        foreach ($this->parameters as $attribute => $value) {
            $result .= self::appendParameter($result, $attribute, $value, encoded: null);
        }

        return $result;
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        $result = $this->disposition;
        foreach ($this->parameters as $attribute => $value) {
            $encoded = Mime::isPrintable($value) ? null : HeaderWrap::fold('Content-Disposition', $value);
            $result  .= self::appendParameter($result, $attribute, $value, $encoded);
        }

        return $result;
    }

    #[Override]
    public function toString(): string
    {
        return "Content-Disposition: {$this->getEncodedFieldValue()}";
    }

    /**
     * One parameter, on the current line when it fits, on a folded line when
     * it does not, or split into RFC 2231 continuations when it is too long
     * for any line.
     *
     * @param string|null $encoded The RFC 2047 encoded value, when the value is not ASCII.
     */
    private static function appendParameter(string $result, string $attribute, string $value, ?string $encoded): string
    {
        $line = sprintf('%s="%s"', $attribute, $encoded ?? $value);
        if (strlen($line) >= self::MAX_PARAMETER_LENGTH) {
            return null === $encoded
                ? ParameterContinuation::split($attribute, $value)
                : ParameterContinuation::splitEncoded($attribute, $value);
        }

        return self::fitsOnCurrentLine($result, $line) ? "; {$line}" : ';' . Headers::FOLDING . $line;
    }

    private static function fitsOnCurrentLine(string $result, string $line): bool
    {
        $lines              = explode(Headers::FOLDING, $result);
        $existingLineLength = 1 === count($lines)
            ? strlen("Content-Disposition: {$result}")
            : 1 + strlen($lines[array_key_last($lines)] ?? '');

        return (2 + $existingLineLength + strlen($line)) <= self::MAX_PARAMETER_LENGTH;
    }
}
