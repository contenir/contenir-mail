<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Tnef;

/**
 * A file read from a TNEF container.
 *
 * Pass it on with Mime\Attachment::fromString($attachment->content,
 * $attachment->filename, $attachment->type), or store it under its
 * file name, which is already safe to create in a directory.
 *
 * @api
 */
final readonly class Attachment
{
    /**
     * @param string $filename The long file name, or else the title, made safe by SafeText::filename().
     * @param string $content The file's bytes, as the sender gave them; untrusted.
     * @param string $type The lower-case media type the sender gave, or application/octet-stream.
     *
     * @internal The TNEF reader builds attachments.
     */
    public function __construct(
        public string $filename,
        public string $content,
        public string $type,
    ) {}
}
