<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Xoauth2;

use Contenir\Mail\Header\SafeText;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function chr;
use function is_array;
use function is_scalar;
use function json_decode;
use function sprintf;

/**
 * @internal
 */
final class Xoauth2
{
    /**
     * encodes accessToken and target mailbox to Xoauth2 SASL base64 encoded string
     */
    public static function encodeXoauth2Sasl(string $targetMailbox, #[SensitiveParameter] string $accessToken): string
    {
        return base64_encode(
            sprintf(
                'user=%s%sauth=Bearer %s%s%s',
                $targetMailbox,
                chr(0x01),
                $accessToken,
                chr(0x01),
                chr(0x01),
            ),
        );
    }

    /**
     * The message for a refused token. A server refuses one with a challenge holding base64
     * JSON, such as {"status":"401","schemes":"bearer","scope":"…"}, which the client
     * must answer with an empty response before the refusal itself. That refusal often
     * says what to do, as Gmail does when POP is turned off, so it follows the status.
     */
    public static function refusal(string $challenge, string $reply = ''): string
    {
        $status  = self::status(json_decode((string) base64_decode($challenge, strict: true), associative: true));
        $message = '' === $status
            ? 'The server refused the access token'
            : sprintf('The server refused the access token (status %s)', $status);
        $reply = SafeText::display($reply);

        return '' === $reply ? $message : "{$message}: {$reply}";
    }

    private static function status(mixed $details): string
    {
        if (! is_array($details) || ! is_scalar($details['status'] ?? null)) {
            return '';
        }

        return SafeText::display((string) $details['status']);
    }
}
