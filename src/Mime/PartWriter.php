<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Headers;

/**
 * Writes the body of a MIME tree: the encoded content of a leaf, or each
 * child of a multipart with its headers between boundary lines.
 */
final class PartWriter
{
    private function __construct() {}

    /**
     * @throws Exception\RuntimeException When a multipart has no boundary in its Content-Type.
     */
    public static function body(PartInterface $part): string
    {
        if (! $part->isMultipart()) {
            return $part->getEncodedContent();
        }

        $boundary = self::boundary($part);
        $body     = '';
        foreach ($part->getParts() as $child) {
            $body .=
                "--{$boundary}"
                . Headers::EOL
                . $child->getHeaders()->toString()
                . Headers::EOL
                . self::body($child)
                . Headers::EOL;
        }

        return "{$body}--{$boundary}--";
    }

    /**
     * @throws Exception\RuntimeException
     */
    private static function boundary(PartInterface $part): string
    {
        $contentType = $part->getHeaders()->get('Content-Type');
        $boundary    = $contentType instanceof ContentType ? $contentType->getParameter('boundary') : null;
        if (null === $boundary || '' === $boundary) {
            throw new Exception\RuntimeException('A multipart part has no boundary in its Content-Type');
        }

        return $boundary;
    }
}
