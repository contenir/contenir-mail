<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Part;

use Contenir\Mail\Header\ContentDisposition;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\Part;

use function array_reverse;

/**
 * Finds the part that holds a message's text or HTML body.
 *
 * The search is depth first. Parts disposed as attachments are skipped, and
 * the parts of a multipart/alternative are tried last to first, because
 * senders order them from the plainest to the richest.
 *
 * @internal
 */
final class BodySelector
{
    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

    /**
     * @param string $contentType The lower-case media type wanted, such as "text/html".
     * @throws RuntimeException When the parts cannot be read.
     */
    public static function find(Part $part, string $contentType): ?Part
    {
        if (self::isAttachment($part)) {
            return null;
        }

        if (! $part->isMultipart()) {
            return $contentType === $part->getContentType() ? $part : null;
        }

        $children = $part->getParts();
        if ('multipart/alternative' === $part->getContentType()) {
            $children = array_reverse($children);
        }

        foreach ($children as $child) {
            $found = self::find($child, $contentType);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    private static function isAttachment(Part $part): bool
    {
        $disposition = $part->getHeaders()->get('Content-Disposition');

        return $disposition instanceof ContentDisposition && 'attachment' === $disposition->getDisposition();
    }
}
