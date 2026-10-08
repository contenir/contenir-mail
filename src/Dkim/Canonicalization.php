<?php

declare(strict_types=1);

namespace Contenir\Mail\Dkim;

use Contenir\Mail\Headers;

use function preg_replace;
use function rtrim;
use function strlen;
use function strstr;
use function strtolower;
use function substr;
use function trim;

/**
 * The DKIM canonicalisation algorithms (RFC 6376, section 3.4), as written in the c= tag.
 *
 * "simple" tolerates no change at all on the way; "relaxed" tolerates the
 * changes relays commonly make: refolded headers, a different case in header
 * names, and changed white space.
 */
enum Canonicalization: string
{
    case Simple = 'simple';

    case Relaxed = 'relaxed';

    /**
     * One header field in canonical form, ending with a CRLF.
     *
     * @param string $field The header as written, "Name: value", folded with CRLF, without a trailing line break.
     */
    public function header(string $field): string
    {
        if (self::Simple === $this) {
            return $field . Headers::EOL;
        }

        $name  = (string) strstr($field, needle: ':', before_needle: true);
        $value = substr($field, offset: strlen($name) + 1);
        $value = (string) preg_replace('/\r\n(?=[ \t])/', replacement: '', subject: $value);
        $value = (string) preg_replace('/[ \t]+/', replacement: ' ', subject: $value);

        return strtolower(rtrim($name, characters: " \t")) . ':' . trim($value, characters: ' ') . Headers::EOL;
    }

    /**
     * The body in canonical form.
     *
     * @param string $body The body with every line ending in CRLF, as it is sent.
     */
    public function body(string $body): string
    {
        if (self::Relaxed === $this) {
            $body = (string) preg_replace('/[ \t]+/', replacement: ' ', subject: $body);
            $body = (string) preg_replace('/ (?=\r\n|$)/D', replacement: '', subject: $body);
        }

        $body = rtrim($body, characters: Headers::EOL);

        return '' === $body && self::Relaxed === $this ? '' : $body . Headers::EOL;
    }
}
