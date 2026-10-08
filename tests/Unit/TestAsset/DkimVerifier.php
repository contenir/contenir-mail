<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

use Contenir\Mail\Dkim\Canonicalization;

use function array_key_exists;
use function array_reverse;
use function base64_decode;
use function base64_encode;
use function chunk_split;
use function explode;
use function hash;
use function hash_equals;
use function openssl_verify;
use function preg_replace;
use function preg_split;
use function sodium_crypto_sign_verify_detached;
use function str_contains;
use function strpos;
use function strstr;
use function strtolower;
use function substr;
use function trim;

use const OPENSSL_ALGO_SHA256;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Verifies a DKIM signature in a raw message against a key record, as a receiving server would,
 * without looking the record up in DNS.
 *
 * It checks the body hash and the signature only: not expiry, the d= and i= relationship or policy.
 *
 * @mago-expect lint:cyclomatic-complexity A verifier reads every tag a signature may carry, as a receiving server would.
 */
final class DkimVerifier
{
    /**
     * "pass", or why the signature fails.
     *
     * @param string $message The message as received, with CRLF line endings.
     * @param string $keyRecord The TXT record, such as "v=DKIM1; k=ed25519; p=…".
     * @param int $index Which DKIM-Signature header to check, counting from the top.
     */
    public static function verify(string $message, string $keyRecord, int $index = 0): string
    {
        $tags = self::signatureTags($message, $index);
        if ([] === $tags) {
            return 'no signature';
        }

        if (! hash_equals(
            $tags['bh'] ?? '',
            base64_encode(hash('sha256', self::signedBody($message, $index), binary: true)),
        )) {
            return 'body hash mismatch';
        }

        $data      = self::signedData($message, $index);
        $signature = (string) base64_decode($tags['b'] ?? '', strict: true);
        $public    = self::tags($keyRecord)['p'] ?? '';
        $chunked   = chunk_split($public, length: 64, separator: "\n");
        $pem       = "-----BEGIN PUBLIC KEY-----\n{$chunked}-----END PUBLIC KEY-----\n";
        $verified  = 'ed25519-sha256' === ($tags['a'] ?? '')
            ? sodium_crypto_sign_verify_detached(
                $signature,
                hash('sha256', $data, binary: true),
                (string) base64_decode($public, strict: true),
            )
            : 1 === openssl_verify(
                $data,
                $signature,
                $pem,
                OPENSSL_ALGO_SHA256,
            );

        return $verified ? 'pass' : 'signature mismatch';
    }

    /**
     * The tags of the signature, with white space removed from the values; none when there is no such signature.
     *
     * @return array<string, string>
     */
    public static function signatureTags(string $message, int $index = 0): array
    {
        $signature = self::parse($message)['signatures'][$index] ?? null;

        return null === $signature ? [] : self::tags(substr($signature, (int) strpos($signature, needle: ':') + 1));
    }

    /**
     * The canonical body the signature's bh= covers.
     */
    public static function signedBody(string $message, int $index = 0): string
    {
        $tags = self::signatureTags($message, $index);
        $body = self::canonicalization($tags, 1)->body(self::parse($message)['body']);

        return array_key_exists('l', $tags) ? substr($body, offset: 0, length: (int) $tags['l']) : $body;
    }

    /**
     * The canonical header data the signature's b= signs.
     */
    public static function signedData(string $message, int $index = 0): string
    {
        $parsed    = self::parse($message);
        $signature = $parsed['signatures'][$index] ?? '';
        $tags      = self::signatureTags($message, $index);
        $header    = self::canonicalization($tags, 0);

        $data = '';
        $used = [];
        foreach (explode(':', $tags['h'] ?? '') as $name) {
            $name        = strtolower(trim($name));
            $instances   = array_reverse($parsed['fields'][$name] ?? []);
            $position    = $used[$name] ?? 0;
            $used[$name] = $position + 1;
            if (array_key_exists($position, $instances)) {
                $data .= $header->header($instances[$position]);
            }
        }

        $colon    = (int) strpos($signature, needle: ':') + 1;
        $unsigned = (string) preg_replace(
            '/((?:^|;)\s*b\s*=)[^;]*/',
            replacement: '$1',
            subject: substr($signature, $colon),
        );

        $field = $header->header(substr($signature, offset: 0, length: $colon) . $unsigned);

        return $data . substr($field, offset: 0, length: -2);
    }

    /**
     * The tags of a signature or key record, with all white space removed from the values.
     *
     * @return array<string, string>
     */
    public static function tags(string $list): array
    {
        $tags = [];
        foreach (explode(';', $list) as $tag) {
            if (! str_contains($tag, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $tag, limit: 2);
            $tags[trim($name)] = (string) preg_replace('/\s+/', replacement: '', subject: $value);
        }

        return $tags;
    }

    /**
     * @param array<string, string> $tags
     * @param 0|1 $part 0 for the header canonicalisation, 1 for the body's.
     */
    private static function canonicalization(array $tags, int $part): Canonicalization
    {
        return Canonicalization::from(explode('/', ($tags['c'] ?? 'simple') . '/simple')[$part]);
    }

    /**
     * @return array{fields: array<string, list<string>>, signatures: list<string>, body: string}
     */
    private static function parse(string $message): array
    {
        $split      = strpos($message, needle: "\r\n\r\n");
        $block      = false === $split ? $message : substr($message, offset: 0, length: $split + 2);
        $fields     = [];
        $signatures = [];
        foreach ((array) preg_split('/\r\n(?![ \t])/', $block, flags: PREG_SPLIT_NO_EMPTY) as $field) {
            $name            = strtolower(trim((string) strstr((string) $field, needle: ':', before_needle: true)));
            $fields[$name][] = (string) $field;
            if ('dkim-signature' === $name) {
                $signatures[] = (string) $field;
            }
        }

        return [
            'fields'     => $fields,
            'signatures' => $signatures,
            'body'       => false === $split ? '' : substr($message, $split + 4),
        ];
    }
}
