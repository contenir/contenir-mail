<?php

declare(strict_types=1);

namespace Contenir\Mail\Dkim;

use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Exception\RuntimeException;
use LogicException;
use OpenSSLAsymmetricKey;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function file_get_contents;
use function function_exists;
use function hash;
use function hash_equals;
use function is_file;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_sign;
use function preg_match;
use function preg_replace;
use function restore_error_handler;
use function set_error_handler;
use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_publickey_from_secretkey;
use function sodium_crypto_sign_secretkey;
use function sodium_crypto_sign_seed_keypair;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_RSA;

/**
 * A private key to sign DKIM signatures with: RSA, or Ed25519 (RFC 8463).
 *
 * The key never leaves the object: var_dump() and print_r() show it as
 * hidden, and the object cannot be serialized.
 *
 * ```php
 * PrivateKey::fromFile('/etc/dkim/2026.pem');
 * PrivateKey::fromPem($pem, passphrase: $passphrase);
 * PrivateKey::fromEd25519('nWGxne/9WmC6hEr0kuwsxERJxWl7MmkZcDusAxyuf2A=');
 * ```
 *
 * RSA keys need ext-openssl, and Ed25519 keys ext-sodium; reading an Ed25519 key from PEM needs both.
 *
 * @mago-expect lint:cyclomatic-complexity Reads RSA and Ed25519 keys in each form they are kept in, and checks each.
 */
final readonly class PrivateKey
{
    /** Shown in place of the key by var_dump() and print_r() */
    public const string REDACTED = '[hidden]';

    /** The smallest RSA key accepted; RFC 8301 requires verifiers to accept 1024 bits, and 2048 are recommended */
    public const int MIN_RSA_BITS = 1024;

    /** The PKCS #8 DER of an Ed25519 private key up to its 32-byte seed (RFC 8410, section 7) */
    private const string ED25519_PKCS8_PREFIX = "\x30\x2e\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x70\x04\x22\x04\x20";

    /** An RSA key, or the 64-byte Ed25519 secret key libsodium signs with */
    private OpenSSLAsymmetricKey|string $key;

    public Algorithm $algorithm;

    private function __construct(#[SensitiveParameter] OpenSSLAsymmetricKey|string $key)
    {
        $this->key       = $key;
        $this->algorithm = $key instanceof OpenSSLAsymmetricKey ? Algorithm::RsaSha256 : Algorithm::Ed25519Sha256;
    }

    /**
     * An RSA or Ed25519 key in PEM, encrypted or not.
     *
     * @throws InvalidArgumentException When the PEM cannot be read, the passphrase is wrong, or the key is
     *     neither RSA of at least 1024 bits nor Ed25519.
     * @throws RuntimeException When ext-openssl is not loaded.
     */
    public static function fromPem(
        #[SensitiveParameter]
        string $pem,
        #[SensitiveParameter]
        ?string $passphrase = null,
    ): self {
        if (! function_exists('openssl_pkey_get_private')) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Reading a PEM DKIM key needs the openssl extension (ext-openssl)');

            // @codeCoverageIgnoreEnd
        }

        /** An empty passphrase, never null: given null, OpenSSL asks for one on the terminal */
        $key = openssl_pkey_get_private($pem, $passphrase ?? '');
        if (false === $key) {
            throw new InvalidArgumentException(
                'The DKIM private key could not be read; give a PEM private key and, if it is encrypted, its passphrase',
            );
        }

        return self::fromOpenSsl($key);
    }

    /**
     * A key read from a local file: a PEM key, or an Ed25519 key in base64.
     *
     * @throws InvalidArgumentException When the path is a stream wrapper URL or cannot be read, or the key is invalid.
     * @throws RuntimeException When the extension the key needs is not loaded.
     */
    public static function fromFile(string $path, #[SensitiveParameter] ?string $passphrase = null): self
    {
        if (1 === preg_match('/^[A-Za-z][A-Za-z0-9+.-]+:/', $path)) {
            throw new InvalidArgumentException(sprintf(
                'The DKIM private key must be a local file, not a stream wrapper URL; received "%s"',
                $path,
            ));
        }

        $error = '';
        set_error_handler(static function (int $_number, string $text) use (&$error): bool {
            $error = $text;
            return true;
        });
        $contents = is_file($path) ? file_get_contents($path) : false;
        restore_error_handler();
        if (false === $contents) {
            throw new InvalidArgumentException(sprintf(
                'Unable to read the DKIM private key file "%s"%s',
                $path,
                '' === $error ? '' : ": {$error}",
            ));
        }

        return str_contains($contents, '-----BEGIN')
            ? self::fromPem($contents, $passphrase)
            : self::fromEd25519(trim($contents));
    }

    /**
     * An RSA key of at least 1024 bits, or an Ed25519 key, loaded by ext-openssl.
     *
     * @throws InvalidArgumentException When the key is neither.
     * @throws RuntimeException When the key is Ed25519 and ext-sodium is not loaded.
     */
    public static function fromOpenSsl(#[SensitiveParameter] OpenSSLAsymmetricKey $key): self
    {
        $details = openssl_pkey_get_details($key);
        $type    = $details['type'] ?? null;
        $bits    = $details['bits'] ?? 0;
        if (OPENSSL_KEYTYPE_RSA === $type) {
            if ($bits < self::MIN_RSA_BITS) {
                throw new InvalidArgumentException(sprintf(
                    'The DKIM RSA key has %d bits; at least %d are required, and 2048 or more are recommended',
                    $bits,
                    self::MIN_RSA_BITS,
                ));
            }

            return new self($key);
        }

        $exported = '';
        openssl_pkey_export($key, $exported);
        $der = (string) base64_decode(self::unarmour($exported), strict: true);
        if (! str_starts_with($der, self::ED25519_PKCS8_PREFIX)) {
            throw new InvalidArgumentException('The DKIM private key must be an RSA or an Ed25519 key');
        }

        return self::fromEd25519(substr($der, offset: 16));
    }

    /**
     * An Ed25519 key: the 32-byte seed or the 64-byte libsodium secret key, raw or in base64.
     *
     * @throws InvalidArgumentException When the key is not 32 or 64 bytes, or a 64-byte key does not hold its own public key.
     * @throws RuntimeException When ext-sodium is not loaded.
     *
     * @mago-expect analysis:unhandled-thrown-type libsodium throws only for a seed that is not 32 bytes, which is checked first.
     */
    public static function fromEd25519(#[SensitiveParameter] string $key): self
    {
        if (! function_exists('sodium_crypto_sign_detached')) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException('DKIM signing with Ed25519 needs the sodium extension (ext-sodium)');

            // @codeCoverageIgnoreEnd
        }

        $length = strlen($key);
        if (44 === $length || 88 === $length) {
            $key    = (string) base64_decode($key, strict: true);
            $length = strlen($key);
        }

        if (32 === $length) {
            return new self(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($key)));
        }

        if (64 !== $length) {
            throw new InvalidArgumentException(
                'An Ed25519 DKIM key must be a 32-byte seed or a 64-byte secret key, raw or in base64',
            );
        }

        $expected = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(substr($key, offset: 0, length: 32)));
        if (! hash_equals($expected, $key)) {
            throw new InvalidArgumentException(
                'The Ed25519 DKIM secret key does not hold the public key of its seed; it is corrupt',
            );
        }

        return new self($key);
    }

    /**
     * The TXT record to publish at "selector._domainkey.domain" for this key.
     *
     * @mago-expect analysis:unhandled-thrown-type libsodium throws only for a secret key that is not 64 bytes, which fromEd25519() ensures.
     */
    public function dnsRecord(): string
    {
        if ($this->key instanceof OpenSSLAsymmetricKey) {
            $public = openssl_pkey_get_details($this->key)['key'] ?? '';

            return 'v=DKIM1; k=rsa; p=' . self::unarmour($public);
        }

        return 'v=DKIM1; k=ed25519; p=' . base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->key));
    }

    /**
     * Sign the canonicalised header data, as the algorithm requires.
     *
     * @internal
     * @throws RuntimeException When OpenSSL fails to sign.
     *
     * @mago-expect analysis:unhandled-thrown-type libsodium throws only for a secret key that is not 64 bytes, which fromEd25519() ensures.
     */
    public function sign(string $data): string
    {
        if (! $this->key instanceof OpenSSLAsymmetricKey) {
            return sodium_crypto_sign_detached(hash('sha256', $data, binary: true), $this->key);
        }

        $signature = '';
        if (! openssl_sign($data, $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException('OpenSSL could not sign the DKIM signature');

            // @codeCoverageIgnoreEnd
        }

        return $signature;
    }

    /**
     * A key cannot be serialized, so it never reaches a queue or a cache.
     *
     * @return never
     * @throws LogicException
     */
    public function __serialize(): array
    {
        throw new LogicException(self::class . ' cannot be serialized');
    }

    /**
     * @return array{algorithm: string, key: string}
     */
    public function __debugInfo(): array
    {
        return ['algorithm' => $this->algorithm->value, 'key' => self::REDACTED];
    }

    /**
     * The base64 inside a PEM block, without its armour lines and line breaks.
     */
    private static function unarmour(string $pem): string
    {
        return (string) preg_replace('/-----[^-]+-----|\s+/', replacement: '', subject: $pem);
    }
}
