<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport;

use Contenir\Mail\Headers;

use function addcslashes;
use function preg_match;
use function sprintf;

/**
 * The last check before headers leave a transport: every header must be one line, apart
 * from folding (CRLF followed by a space or tab). The built-in headers already ensure
 * this; the check catches custom HeaderInterface implementations that do not.
 *
 * @internal
 */
final readonly class HeaderGuard
{
    /** A line break that is not folding: CRLF not followed by WSP, a bare CR or a bare LF */
    private const string UNFOLDED_BREAK = '/\r\n(?![ \t])|\r(?!\n)|(?<!\r)\n/';

    /**
     * @throws Exception\RuntimeException When a header contains a line break that is not folding.
     */
    public static function check(Headers $headers): Headers
    {
        foreach ($headers as $header) {
            if (1 === preg_match(self::UNFOLDED_BREAK, $header->toString())) {
                throw new Exception\RuntimeException(sprintf(
                    'Header "%s" contains a line break that is not folding; not sending it',
                    addcslashes($header->getFieldName(), characters: "\0..\37\177"),
                ));
            }
        }

        return $headers;
    }
}
