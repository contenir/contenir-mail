<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use function ctype_digit;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function strcspn;
use function strlen;
use function strpos;
use function strspn;
use function strtolower;
use function substr;
use function trim;

/**
 * Reads the parameters of structured MIME headers, `value; name="value"; ...`
 * (RFC 2045, section 5.1), with RFC 2231 continuations and extended values.
 *
 * The structure is parsed from the value as written, and only then are
 * values decoded, so text that decodes to ";", "=" or a quote can never
 * change where a parameter ends. Control characters are removed from
 * decoded values, and a tab becomes a space.
 *
 * Parameters keep the order they first appear in. A parameter without "="
 * is skipped, and a later parameter of the same name replaces an earlier one.
 *
 * @internal Used by ContentType and ContentDisposition.
 */
final class MimeParameterParser
{
    /** Most continuation sections one parameter may have */
    public const int MAX_SECTIONS = 100;

    public const string WHITESPACE = " \t";

    /** Characters that end a parameter name */
    private const string NAME_END = "=; \t";

    /** @var array<string, string> */
    private array $parameters = [];

    /** @var array<string, array<int, array{string, bool}>> value and whether it is extended, by section number */
    private array $sections = [];

    private function __construct(
        private readonly string $headerLine,
        private readonly string $fieldName,
    ) {}

    /**
     * Split a raw header value into its leading value and its parameters.
     *
     * @return array{string, array<string, string>}
     * @throws Exception\InvalidArgumentException When a continuation section is not numeric, is missing, or there are too many.
     */
    public static function parse(string $value, string $headerLine, string $fieldName): array
    {
        $value  = (string) preg_replace('/\r\n[ \t]/', replacement: ' ', subject: $value);
        $offset = strcspn($value, characters: ';');
        $length = strlen($value);
        $parser = new self($headerLine, $fieldName);
        while ($offset !== $length) {
            $offset = $parser->read($value, $offset + 1);
        }

        return [trim(substr($value, offset: 0, length: strcspn($value, characters: ';'))), $parser->finish()];
    }

    /**
     * Read one parameter starting at $offset, just after a ";".
     *
     * @return int The offset of the next ";", or the end.
     * @throws Exception\InvalidArgumentException When a section number is not numeric.
     */
    private function read(string $value, int $offset): int
    {
        $offset     += strspn($value, self::WHITESPACE, $offset);
        $nameLength = strcspn($value, characters: self::NAME_END, offset: $offset);
        $name       = strtolower(substr($value, $offset, $nameLength));
        $offset     += $nameLength;
        $offset     += strspn($value, self::WHITESPACE, $offset);
        if ('' === $name || '=' !== substr($value, $offset, length: 1)) {
            return $offset + strcspn($value, characters: ';', offset: $offset);
        }

        ++$offset;
        $offset += strspn($value, self::WHITESPACE, $offset);
        [$parameter, $offset] = '"' === substr($value, $offset, length: 1)
            ? ParameterText::unquote($value, $offset + 1)
            : ParameterText::token($value, $offset);

        $this->add($name, $parameter);

        return $offset + strcspn($value, characters: ';', offset: $offset);
    }

    /**
     * @throws Exception\InvalidArgumentException When a section number is not numeric.
     */
    private function add(string $name, string $parameter): void
    {
        $star = strpos($name, needle: '*');
        if (false === $star) {
            $this->parameters[$name] = ParameterText::clean(EncodedWordDecoder::decode($parameter));

            return;
        }

        $rest    = substr($name, $star + 1);
        $section = rtrim($rest, characters: '*');
        $section = '' === $section ? '0' : $section;
        if (! ctype_digit($section)) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Invalid header line for %s string - count expected to be numeric, got "%s"',
                $this->fieldName,
                $section,
            ));
        }

        $base                                  = substr($name, offset: 0, length: $star);
        $this->parameters[$base]               = '';
        $this->sections[$base][(int) $section] = [$parameter, '' === $rest || str_ends_with($rest, '*')];
    }

    /**
     * @return array<string, string>
     * @throws Exception\InvalidArgumentException When a section is missing or there are too many.
     */
    private function finish(): array
    {
        foreach ($this->sections as $name => $parts) {
            $this->parameters[$name] = ParameterText::join($parts, $this->headerLine, $this->fieldName);
        }

        return $this->parameters;
    }
}
