<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

use function sprintf;
use function str_contains;
use function strlen;
use function substr;

/**
 * Watches the text of one part of a multipart, given a piece at a time, for a line
 * that starts with the multipart's boundary delimiter (RFC 2046, section 5.1.1).
 *
 * A delimiter split between two pieces is found as well as one inside a piece.
 *
 * @internal
 */
final class BoundaryGuard
{
    /** The delimiter with the line feed that ends the line before it */
    private readonly string $delimiter;

    /**
     * The end of the text so far, as long as the delimiter, so that one split between
     * pieces is found; a line feed to begin with, as the part starts a line.
     */
    private string $tail = "\n";

    public function __construct(
        private readonly string $boundary,
    ) {
        $this->delimiter = "\n--{$boundary}";
    }

    /**
     * @throws Exception\RuntimeException When a line of the part starts with the boundary delimiter.
     */
    public function check(string $bytes): void
    {
        $text = $this->tail . $bytes;
        if (str_contains($text, $this->delimiter)) {
            throw new Exception\RuntimeException(sprintf(
                'A part contains a line starting with its multipart boundary "%s"; choose another boundary',
                $this->boundary,
            ));
        }

        $this->tail = substr($text, -strlen($this->delimiter));
    }
}
