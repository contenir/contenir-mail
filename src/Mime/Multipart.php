<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Headers;
use Override;

use function array_values;
use function md5;
use function preg_match;
use function uniqid;

/**
 * A multipart node of a MIME tree, holding other parts between boundary lines.
 */
final readonly class Multipart implements PartInterface
{
    /** RFC 2046 boundary characters, 1 to 70 of them, not ending in a space */
    private const string BOUNDARY = '/^[0-9A-Za-z\'()+_,\-.\/:=? ]{0,69}[0-9A-Za-z\'()+_,\-.\/:=?]$/D';

    /** @var list<PartInterface> */
    private array $parts;

    private string $boundary;

    /**
     * @param list<PartInterface> $parts
     * @param string|null $boundary A random boundary is generated when none is given.
     * @throws Exception\InvalidArgumentException When there are no parts or the boundary is not valid.
     */
    public function __construct(
        private MultipartType $type,
        array $parts,
        ?string $boundary = null,
    ) {
        if ([] === $parts) {
            throw new Exception\InvalidArgumentException('A multipart needs at least one part');
        }

        // "=_" cannot appear in quoted-printable or base64 content, so the boundary never collides with it;
        // the rest only needs to be unique, not unpredictable
        $boundary ??= '=_' . md5(uniqid(more_entropy: true));
        if (1 !== preg_match(self::BOUNDARY, $boundary)) {
            throw new Exception\InvalidArgumentException("Invalid MIME boundary \"{$boundary}\"");
        }

        $this->parts    = array_values($parts);
        $this->boundary = $boundary;
    }

    public function getType(): MultipartType
    {
        return $this->type;
    }

    public function getBoundary(): string
    {
        return $this->boundary;
    }

    #[Override]
    public function getHeaders(): Headers
    {
        return new Headers(new ContentType($this->type->contentType(), ['boundary' => $this->boundary]));
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
