<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Imap;

use Contenir\Mail\Protocol\ErrorCapture;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Utf8;

use function base64_decode;
use function base64_encode;
use function iconv;
use function is_string;
use function preg_replace_callback;
use function rtrim;
use function str_replace;

/**
 * Mailbox names in modified UTF-7 (RFC 3501, section 5.1.3), as IMAP4rev1
 * servers expect them: printable ASCII as itself, "&" as "&-", and any other
 * run of characters as UTF-16 in modified base64 between "&" and "-".
 *
 * Servers that have IMAP4rev2 or UTF8=ACCEPT enabled take UTF-8 names as they are.
 *
 * @internal Used by Contenir\Mail\Protocol\Imap.
 */
final class MailboxName
{
    /** A run of characters outside printable ASCII */
    private const string NON_ASCII = '/[^\x20-\x7E]+/';

    /** A shifted run: "&", modified base64, "-" */
    private const string SHIFTED = '/&([A-Za-z0-9+,]*)-/';

    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

    /**
     * @throws InvalidArgumentException When the name is not UTF-8.
     */
    public static function encode(string $name): string
    {
        if (! Utf8::isValid($name)) {
            throw new InvalidArgumentException('A mailbox name must be UTF-8 text');
        }

        return (string) preg_replace_callback(
            self::NON_ASCII,
            /** @param array<array-key, string> $run */
            static fn(array $run): string => (
                '&'
                . str_replace(
                    search: '/',
                    replace: ',',
                    subject: rtrim(
                        base64_encode((string) iconv(
                            from_encoding: 'UTF-8',
                            to_encoding: 'UTF-16BE',
                            string: $run[0] ?? '',
                        )),
                        characters: '=',
                    ),
                )
                . '-'
            ),
            str_replace(
                search: '&',
                replace: '&-',
                subject: $name,
            ),
        );
    }

    /**
     * The name as UTF-8. A run that is not valid modified UTF-7 is kept as it
     * was written, so a name the server sends malformed can still be shown and
     * sent back unchanged.
     */
    public static function decode(string $name): string
    {
        return (string) preg_replace_callback(
            self::SHIFTED,
            /** @param array<array-key, string> $run */
            static fn(array $run): string => self::decodeRun($run[1] ?? '', $run[0] ?? ''),
            $name,
        );
    }

    private static function decodeRun(string $base64, string $written): string
    {
        if ('' === $base64) {
            return '&';
        }

        $bytes = base64_decode(str_replace(
            search: ',',
            replace: '/',
            subject: $base64,
        ), strict: true);
        if (false === $bytes) {
            return $written;
        }

        [$text] = ErrorCapture::run(static fn(): string|false => iconv(
            from_encoding: 'UTF-16BE',
            to_encoding: 'UTF-8',
            string: $bytes,
        ));

        return is_string($text) ? $text : $written;
    }
}
