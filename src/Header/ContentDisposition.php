<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use NoDiscard;
use Override;

use function in_array;
use function sprintf;
use function strlen;
use function strtolower;

/**
 * How a part is presented, inline or as an attachment, with its parameters (RFC 2183).
 *
 * @mago-expect lint:too-many-methods The HeaderInterface methods plus typed accessors and with*() for each part.
 */
final readonly class ContentDisposition implements HeaderInterface
{
    /** Longest parameter line before RFC 2231 continuation splits it */
    public const int MAX_PARAMETER_LENGTH = MimeParameters::MAX_SEGMENT_LENGTH;

    private string $disposition;

    /** @var array<string, string> keyed by lower-cased parameter name */
    private array $parameters;

    /**
     * @param array<string, string> $parameters
     * @throws Exception\InvalidArgumentException When the disposition is not a token, or a parameter name or value is invalid.
     */
    public function __construct(string $disposition = 'inline', array $parameters = [])
    {
        if (! MimeParameters::isToken($disposition)) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Content-Disposition expects a token such as "inline" or "attachment"; received "%s"',
                $disposition,
            ));
        }

        $normalised = [];
        foreach ($parameters as $name => $value) {
            $name = MimeParameters::name($name, 'content-disposition');

            // 5 covers the quotes and equals sign of name="value", and the space and semicolon of folding
            if ((strlen($name) + 5) >= self::MAX_PARAMETER_LENGTH) {
                throw new Exception\InvalidArgumentException(
                    'Invalid content-disposition parameter name detected (too long)',
                );
            }

            $normalised[$name] = MimeParameters::value($value);
        }

        $this->disposition = strtolower($disposition);
        $this->parameters  = $normalised;
    }

    /**
     * Reassembles parameters split with RFC 2231 continuations (filename*0=, filename*1=)
     * and decodes extended values (filename*=UTF-8''...) and encoded words.
     */
    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$name, $value] = GenericHeader::splitHeaderLine($headerLine);
        $names = ['contentdisposition', 'content_disposition', 'content-disposition'];
        if (! in_array(strtolower($name), $names, strict: true)) {
            throw new Exception\InvalidArgumentException('Invalid header line for Content-Disposition string');
        }

        [$disposition, $parameters] = MimeParameterParser::parse($value, $headerLine, 'Content-Disposition');

        return new self($disposition, $parameters);
    }

    /**
     * The filename parameter as the sender wrote it.
     *
     * This is untrusted input: it may hold path separators, "..", control or
     * bidirectional characters. Use getSafeFilename() to store or display it.
     */
    public function getFilename(): ?string
    {
        return $this->parameters['filename'] ?? null;
    }

    /**
     * The filename reduced to a safe base name, see SafeText::filename(); null when there is none.
     */
    public function getSafeFilename(): ?string
    {
        $filename = $this->getFilename();

        return null === $filename ? null : SafeText::filename($filename);
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

    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function withDisposition(string $disposition): self
    {
        return new self($disposition, $this->parameters);
    }

    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function withParameter(string $name, string $value): self
    {
        return new self($this->disposition, [...$this->parameters, $name => $value]);
    }

    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
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

    /**
     * The disposition and its parameters on one line, as a reader would see them.
     */
    #[Override]
    public function getFieldValue(): string
    {
        $result = $this->disposition;
        foreach ($this->parameters as $attribute => $value) {
            $result .= sprintf('; %s="%s"', $attribute, $value);
        }

        return $result;
    }

    /**
     * Parameters share a line while they fit; one too long for any line is
     * split into RFC 2231 continuation sections, each on its own line.
     */
    #[Override]
    public function getEncodedFieldValue(): string
    {
        $result = $this->disposition;
        foreach ($this->parameters as $attribute => $value) {
            $result = MimeParameters::append('Content-Disposition', $result, $attribute, $value);
        }

        return $result;
    }

    #[Override]
    public function toString(): string
    {
        return "Content-Disposition: {$this->getEncodedFieldValue()}";
    }
}
