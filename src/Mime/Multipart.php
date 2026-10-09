<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Headers;
use Override;
use Random\RandomException;

use function array_values;
use function bin2hex;
use function preg_match;
use function random_bytes;

/**
 * A multipart node of a MIME tree, holding other parts between boundary lines.
 *
 * @api
 */
final readonly class Multipart implements PartInterface
{
    /** RFC 2046 boundary characters, 1 to 70 of them, not ending in a space */
    private const string BOUNDARY = '/^[0-9A-Za-z\'()+_,\-.\/:=? ]{0,69}[0-9A-Za-z\'()+_,\-.\/:=?]$/D';

    /** @var non-empty-list<PartInterface> */
    private array $parts;

    private string $boundary;

    /**
     * @param list<PartInterface> $parts
     * @param string|null $boundary A random boundary is generated when none is given.
     * @throws Exception\InvalidArgumentException When there are no parts or the boundary is not valid.
     * @throws Exception\RuntimeException When no boundary is given and the system has no source of randomness.
     */
    public function __construct(
        private MultipartType $type,
        array $parts,
        ?string $boundary = null,
    ) {
        if ([] === $parts) {
            throw new Exception\InvalidArgumentException('A multipart needs at least one part');
        }

        $boundary ??= self::randomBoundary();
        if (1 !== preg_match(self::BOUNDARY, $boundary)) {
            throw new Exception\InvalidArgumentException("Invalid MIME boundary \"{$boundary}\"");
        }

        $this->parts    = array_values($parts);
        $this->boundary = $boundary;
    }

    /**
     * "=_" and 32 random hex digits: "=_" cannot appear in quoted-printable or base64 content,
     * so the boundary never collides with an encoded part, and a sender cannot predict it to
     * end a part early in content it does not encode.
     *
     * @throws Exception\RuntimeException When the system has no source of randomness.
     */
    private static function randomBoundary(): string
    {
        try {
            return '=_' . bin2hex(random_bytes(16));

            // @codeCoverageIgnoreStart
            // Unreachable on supported systems, which always have a source of randomness
        } catch (RandomException $e) {
            throw new Exception\RuntimeException('No source of randomness for a MIME boundary', 0, $e);
        }

        // @codeCoverageIgnoreEnd
    }

    public function getType(): MultipartType
    {
        return $this->type;
    }

    public function getBoundary(): string
    {
        return $this->boundary;
    }

    /**
     * The Content-Type with the boundary; multipart/related also names the
     * type of its root part, as RFC 2387 requires.
     */
    #[Override]
    public function getHeaders(): Headers
    {
        $parameters = ['boundary' => $this->boundary];
        $root       = $this->parts[0]->getHeaders()->get('Content-Type');
        if (MultipartType::Related === $this->type && $root instanceof ContentType) {
            $parameters['type'] = $root->getType();
        }

        return new Headers(new ContentType($this->type->contentType(), $parameters));
    }

    #[Override]
    public function isMultipart(): bool
    {
        return true;
    }

    #[Override]
    public function getParts(): array
    {
        return $this->parts;
    }

    #[Override]
    public function getContent(): string
    {
        return '';
    }

    #[Override]
    public function getEncodedContent(): string
    {
        return '';
    }
}
