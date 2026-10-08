<?php

declare(strict_types=1);

namespace Contenir\Mail\Dkim;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Exception\RuntimeException;
use SensitiveParameter;

use function addcslashes;
use function array_map;
use function array_unique;
use function count;
use function in_array;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strrchr;
use function strtolower;
use function substr;

/**
 * Settings for the DKIM signer.
 *
 * ```php
 * new DkimConfig('example.com', 'mail2026', PrivateKey::fromFile('/etc/dkim/mail2026.pem'));
 * DkimConfig::fromIterable([
 *     'domain'           => 'example.com',
 *     'selector'         => 'mail2026',
 *     'private_key_path' => '/etc/dkim/mail2026.pem',
 * ]);
 * ```
 *
 * The algorithm follows the key: rsa-sha256 for an RSA key, ed25519-sha256 for an Ed25519 key.
 *
 * @mago-expect lint:excessive-parameter-list Built with named arguments; every setting but the key and its domain is optional.
 * @mago-expect lint:cyclomatic-complexity Checks each setting where the config is built, so a signer is never built from an invalid one.
 */
final readonly class DkimConfig
{
    /** @var list<string> */
    public const array KEYS = [
        'domain',
        'selector',
        'private_key',
        'private_key_path',
        'private_key_passphrase',
        'expected_algorithm',
        'headers',
        'header_canonicalization',
        'body_canonicalization',
        'identity',
        'sign_body_length',
        'include_timestamp',
        'expires_after',
    ];

    /** The headers signed by default, when the message has them */
    public const array DEFAULT_HEADERS = [
        'From',
        'To',
        'Cc',
        'Subject',
        'Date',
        'Message-ID',
        'Reply-To',
        'In-Reply-To',
        'References',
        'MIME-Version',
        'Content-Type',
        'Content-Transfer-Encoding',
    ];

    /** The longest signed header name: one that still fits a folded line of 78 characters */
    public const int MAX_HEADER_NAME_LENGTH = 76;

    /** A DNS name of letters, digits and hyphens, at most 63 characters a label */
    private const string DOMAIN = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/D';

    /** The local part of an i= identity: dot-atom text without "=", which the tag would have to encode */
    private const string IDENTITY_LOCAL_PART = '/^[A-Za-z0-9!#$%&\'*+\/?^_`{|}~.-]*$/D';

    /** The algorithm of the private key */
    public Algorithm $algorithm;

    /**
     * @param string $domain The signing domain (d=), which the key's DNS record is published under.
     * @param string $selector The selector (s=): the key's record is at "selector._domainkey.domain".
     * @param PrivateKey $privateKey An RSA key of at least 2048 bits, or an Ed25519 key.
     * @param Algorithm|null $expectedAlgorithm The key's algorithm; given only to check the key is the one expected.
     * @param list<string> $headers The headers to sign when the message has them. From is required.
     * @param Canonicalization $headerCanonicalization Relaxed by default, which survives refolding by relays.
     * @param Canonicalization $bodyCanonicalization Relaxed by default, which survives changed white space.
     * @param string|null $identity The agent or user signed for (i=), at the domain or a subdomain of it, such as "@example.com".
     * @param bool $signBodyLength Write the length of the signed body (l=). Off by default: anyone could then
     *     append content to the message, such as a new MIME part, and the signature would still verify.
     * @param bool $includeTimestamp Write the signing time (t=), from the signer's clock.
     * @param int|null $expiresAfter Seconds after signing that the signature expires (x=); none by default.
     * @throws InvalidArgumentException When a value is invalid.
     */
    public function __construct(
        public string $domain,
        public string $selector,
        #[SensitiveParameter]
        public PrivateKey $privateKey,
        ?Algorithm $expectedAlgorithm = null,
        public array $headers = self::DEFAULT_HEADERS,
        public Canonicalization $headerCanonicalization = Canonicalization::Relaxed,
        public Canonicalization $bodyCanonicalization = Canonicalization::Relaxed,
        public ?string $identity = null,
        public bool $signBodyLength = false,
        public bool $includeTimestamp = true,
        public ?int $expiresAfter = null,
    ) {
        self::checkName('domain', $domain);
        self::checkName('selector', $selector);
        if (strlen("{$selector}._domainkey.{$domain}") > 253) {
            throw new InvalidArgumentException(sprintf(
                'The DKIM key record name "%s._domainkey.%s" is longer than the 253 characters DNS allows',
                $selector,
                $domain,
            ));
        }

        if (null !== $expectedAlgorithm && $expectedAlgorithm !== $privateKey->algorithm) {
            throw new InvalidArgumentException(sprintf(
                'The DKIM algorithm is %s, but the private key is for %s',
                $expectedAlgorithm->value,
                $privateKey->algorithm->value,
            ));
        }

        $this->algorithm = $privateKey->algorithm;
        self::checkHeaders($headers);
        if (null !== $identity) {
            self::checkIdentity($identity, $domain);
        }

        if (null !== $expiresAfter && $expiresAfter < 1) {
            throw new InvalidArgumentException(sprintf(
                'A DKIM signature must expire at least one second after signing; received %d',
                $expiresAfter,
            ));
        }
    }

    /**
     * @param iterable<mixed, mixed> $config The keys in KEYS. The key is given as "private_key", a PEM
     *     key or an Ed25519 key in base64, or as "private_key_path", a file holding either.
     * @throws InvalidArgumentException When a key is unknown, the private key is missing or given twice, or a value is invalid.
     * @throws RuntimeException When the extension the key needs is not loaded.
     */
    public static function fromIterable(#[SensitiveParameter] iterable $config): self
    {
        $reader    = ConfigReader::read(self::class, $config, self::KEYS);
        $algorithm = $reader->nullableString('expected_algorithm');

        return new self(
            domain: $reader->requiredString('domain'),
            selector: $reader->requiredString('selector'),
            privateKey: self::readKey($reader),
            expectedAlgorithm: null === $algorithm ? null : Algorithm::fromName($algorithm),
            headers: $reader->stringOrList('headers', default: self::DEFAULT_HEADERS),
            headerCanonicalization: $reader->enum('header_canonicalization', default: Canonicalization::Relaxed),
            bodyCanonicalization: $reader->enum('body_canonicalization', default: Canonicalization::Relaxed),
            identity: $reader->nullableString('identity'),
            signBodyLength: $reader->bool('sign_body_length', default: false),
            includeTimestamp: $reader->bool('include_timestamp', default: true),
            expiresAfter: $reader->nullableInt('expires_after'),
        );
    }

    /**
     * @throws InvalidArgumentException When neither or both of the key settings are given, or the key is invalid.
     * @throws RuntimeException When the extension the key needs is not loaded.
     */
    private static function readKey(ConfigReader $reader): PrivateKey
    {
        $key        = $reader->nullableString('private_key');
        $path       = $reader->nullableString('private_key_path');
        $passphrase = $reader->nullableString('private_key_passphrase');
        if ((null === $key) === (null === $path)) {
            throw new InvalidArgumentException(
                DkimConfig::class . ': give the private key as exactly one of "private_key" and "private_key_path"',
            );
        }

        if (null !== $path) {
            return PrivateKey::fromFile($path, $passphrase);
        }

        return str_starts_with((string) $key, '-----BEGIN')
            ? PrivateKey::fromPem((string) $key, $passphrase)
            : PrivateKey::fromEd25519((string) $key);
    }

    /**
     * @throws InvalidArgumentException When the name is not an ASCII domain name.
     */
    private static function checkName(string $setting, string $name): void
    {
        if (1 !== preg_match(self::DOMAIN, $name)) {
            throw new InvalidArgumentException(sprintf(
                'The DKIM %s "%s" must be an ASCII domain name of letters, digits, hyphens and dots, '
                    . 'without white space, semicolons or control characters',
                $setting,
                addcslashes($name, characters: "\0..\37\177"),
            ));
        }
    }

    /**
     * @param list<string> $headers
     * @throws InvalidArgumentException When a name is invalid or listed twice, From is missing, or Bcc or DKIM-Signature is listed.
     */
    private static function checkHeaders(array $headers): void
    {
        foreach ($headers as $name) {
            if (1 !== preg_match('/^[\x21-\x39\x3B-\x7E]{1,' . self::MAX_HEADER_NAME_LENGTH . '}$/D', $name)) {
                throw new InvalidArgumentException(sprintf(
                    'The signed header name "%s" must be 1 to %d printable US-ASCII characters, without a colon',
                    addcslashes($name, characters: "\0..\37\177"),
                    self::MAX_HEADER_NAME_LENGTH,
                ));
            }
        }

        $lower = array_map(strtolower(...), $headers);
        if (count(array_unique($lower)) !== count($lower)) {
            throw new InvalidArgumentException(
                'A header is listed more than once to be signed; each instance of a listed header is signed',
            );
        }

        if (! in_array('from', $lower, strict: true)) {
            throw new InvalidArgumentException('The signed headers must include From (RFC 6376, section 5.4)');
        }

        if (in_array('bcc', $lower, strict: true)) {
            throw new InvalidArgumentException(
                'Bcc cannot be signed: transports remove it before sending, so the signature would not verify',
            );
        }

        if (in_array('dkim-signature', $lower, strict: true)) {
            throw new InvalidArgumentException('DKIM-Signature cannot be listed among the headers it signs');
        }
    }

    /**
     * @throws InvalidArgumentException When the identity is not an address at the domain or a subdomain of it.
     */
    private static function checkIdentity(string $identity, string $domain): void
    {
        $at        = strrchr($identity, needle: '@');
        $host      = strtolower(false === $at ? '' : substr($at, offset: 1));
        $localPart = substr($identity, offset: 0, length: strlen($identity) - strlen($host) - 1);
        $domain    = strtolower($domain);
        if (
            1 !== preg_match(self::IDENTITY_LOCAL_PART, $localPart)
            || 1 !== preg_match(self::DOMAIN, $host)
            || (
                $host !== $domain
                && ! str_ends_with($host, ".{$domain}")
            )
        ) {
            throw new InvalidArgumentException(sprintf(
                'The DKIM identity "%s" must be an address at "%s" or a subdomain of it, such as "@%s"',
                addcslashes($identity, characters: "\0..\37\177"),
                $domain,
                $domain,
            ));
        }
    }
}
