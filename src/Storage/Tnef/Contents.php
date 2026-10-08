<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Tnef;

/**
 * What a TNEF container holds: its attachments, and the message body when it carries one.
 *
 * @api
 */
final readonly class Contents
{
    /**
     * @param list<Attachment> $attachments The files, in the order the container holds them.
     * @param string|null $text The plain-text body as UTF-8, from attBody or else PR_BODY; null when there is none.
     * @param string|null $rtf The RTF body, decompressed from PR_RTF_COMPRESSED; null when there is none.
     *     It is returned as sent and is not safe to render as it is.
     *
     * @internal The TNEF reader builds contents.
     */
    public function __construct(
        public array $attachments,
        public ?string $text,
        public ?string $rtf,
    ) {}
}
