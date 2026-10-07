<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

use Contenir\Mail\Headers;

/**
 * One node of a MIME tree: a leaf with content, or a multipart holding other parts.
 *
 * Parts composed for sending and parts read from storage share this
 * interface, so a received message can be written back out.
 *
 * @api
 */
interface PartInterface
{
    /**
     * The content headers: Content-Type and, where set, Content-Transfer-Encoding,
     * Content-Disposition, Content-ID, Content-Description, Content-Location and Content-Language.
     */
    public function getHeaders(): Headers;

    public function isMultipart(): bool;

    /**
     * The child parts of a multipart; empty for a leaf.
     *
     * @return list<PartInterface>
     */
    public function getParts(): array;

    /**
     * The decoded content of a leaf; empty for a multipart.
     */
    public function getContent(): string;

    /**
     * The content as written on the wire, after its Content-Transfer-Encoding; empty for a multipart.
     */
    public function getEncodedContent(): string;
}
