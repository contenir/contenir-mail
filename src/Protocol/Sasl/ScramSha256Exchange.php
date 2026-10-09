<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Sasl;

use Contenir\Mail\Header\SafeText;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Smtp\Auth\Credentials;
use Override;
use Random\RandomException;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function hash;
use function hash_equals;
use function hash_hmac;
use function hash_pbkdf2;
use function preg_match;
use function random_bytes;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function substr;

/**
 * One SCRAM-SHA-256 exchange as the client (RFC 5802, RFC 7677), in base64 as the protocols carry it.
 *
 * ```php
 * $scram = new ScramSha256Exchange('jo', $password);
 * $challenge = $send($scram->initialResponse());   // client-first, answered by server-first
 * $final     = $send($scram->respond($challenge)); // client-final with the proof, answered by server-final
 * $send($scram->respond($final));                  // the server's signature checked, or an exception
 * $scram->complete();                              // after the server accepts: only once it was checked
 * ```
 *
 * The password never crosses the wire, and the server's signature proves it knows it too, so
 * the exchange fails closed against a server that does not. Use a new instance for each attempt.
 *
 * Channel binding (SCRAM-SHA-256-PLUS) is not offered: it needs the tls-unique or tls-exporter
 * value of the TLS session, which PHP's stream functions do not expose. The gs2 header is
 * therefore "n,,", which tells the server the client does not support binding.
 *
 * @internal ScramSha256 starts it.
 *
 * @mago-expect lint:cyclomatic-complexity Each check RFC 5802 asks of the server's messages is a branch of its own.
 */
final class ScramSha256Exchange implements ExchangeInterface
{
    public const string MECHANISM = 'SCRAM-SHA-256';

    /** The fewest PBKDF2 iterations accepted, the minimum RFC 7677 asks servers for */
    public const int MIN_ITERATIONS = 4096;

    /** The most PBKDF2 iterations accepted, so a server cannot make the client spin */
    public const int MAX_ITERATIONS = 1_000_000;

    /** No channel binding and no authorization identity */
    private const string GS2_HEADER = 'n,,';

    /** RFC 5802 "printable": any visible ASCII character except "," */
    private const string NONCE = '/^[\x21-\x2B\x2D-\x7E]+$/D';

    /** RFC 5802 server-first-message: nonce, salt and iteration count, then any extensions */
    private const string SERVER_FIRST = '/^r=([^,]+),s=([^,]+),i=([0-9]+)(?:,|$)/D';

    private readonly string $password;

    private readonly string $nonce;

    private readonly string $clientFirstBare;

    /** Set by respond(); what verify() expects the server to send */
    private ?string $serverSignature = null;

    /** Whether verify() has checked the server's signature */
    private bool $verified = false;

    /**
     * @param string|null $nonce The client nonce, printable ASCII without ","; random by default, given only in tests.
     * @throws InvalidArgumentException When the username or password cannot be prepared, or the nonce is invalid.
     * @throws RuntimeException When the system has no source of randomness for the nonce.
     */
    public function __construct(string $username, #[SensitiveParameter] string $password, ?string $nonce = null)
    {
        $prep           = new SaslPrep();
        $this->password = $prep->prepare($password, 'password');
        $this->nonce    = $nonce ?? self::randomNonce();
        if (1 !== preg_match(self::NONCE, $this->nonce)) {
            throw new InvalidArgumentException('The SCRAM nonce must be printable ASCII without ","');
        }

        $name = str_replace(
            search: ['=', ','],
            replace: ['=3D', '=2C'],
            subject: $prep->prepare($username, 'username'),
        );
        $this->clientFirstBare = "n={$name},r={$this->nonce}";
    }

    /**
     * The client-first message, which names the user and carries the client nonce.
     */
    #[Override]
    public function initialResponse(): string
    {
        return base64_encode(self::GS2_HEADER . $this->clientFirstBare);
    }

    /**
     * Answer the server-first message with the client-final one, which carries the proof; then
     * check the server-final message, answered with an empty response.
     *
     * @param string $challenge The server-first message, then the server-final one, in base64.
     * @throws RuntimeException When server-first is malformed, asks for an extension, does not extend
     *     the client nonce, or gives an iteration count outside MIN_ITERATIONS to MAX_ITERATIONS; when
     *     server-final does not prove the server knows the password; or when a third challenge comes.
     */
    #[Override]
    public function respond(string $challenge): string
    {
        $expected = $this->serverSignature;
        if (null === $expected) {
            return $this->clientFinal($challenge);
        }

        if ($this->verified) {
            throw new RuntimeException('The server sent another SCRAM-SHA-256 challenge after its final message');
        }

        $this->verify($expected, $challenge);

        return '';
    }

    /**
     * @throws RuntimeException When the server accepted before proving it knows the password.
     */
    #[Override]
    public function complete(): void
    {
        if (! $this->verified) {
            throw new RuntimeException('The server accepted SCRAM-SHA-256 without proving it knows the password');
        }
    }

    #[Override]
    public function refusal(string $reason): string
    {
        return '' === $reason ? 'The server refused the credentials' : $reason;
    }

    /**
     * The client-final message for the server-first one.
     *
     * @throws RuntimeException
     *
     * @mago-expect analysis:possibly-invalid-argument The iteration count was checked to be at least MIN_ITERATIONS.
     */
    private function clientFinal(string $challenge): string
    {
        $serverFirst = self::decode($challenge);
        $matches     = [];
        if (1 !== preg_match(self::SERVER_FIRST, $serverFirst, $matches)) {
            throw new RuntimeException('The server sent an invalid SCRAM-SHA-256 challenge');
        }

        $nonce      = $matches[1] ?? '';
        $iterations = $matches[3] ?? '';
        if ($nonce === $this->nonce || ! str_starts_with($nonce, $this->nonce)) {
            throw new RuntimeException("The server's SCRAM-SHA-256 nonce does not extend the client's");
        }

        $salt = base64_decode($matches[2] ?? '', strict: true);
        if (false === $salt) {
            throw new RuntimeException('The server sent a SCRAM-SHA-256 salt that is not base64');
        }

        $count = (int) $iterations;
        if ($count < self::MIN_ITERATIONS || $count > self::MAX_ITERATIONS) {
            throw new RuntimeException(sprintf(
                'The server asked for %s SCRAM-SHA-256 iterations; between %d and %d are accepted',
                $iterations,
                self::MIN_ITERATIONS,
                self::MAX_ITERATIONS,
            ));
        }

        $salted       = hash_pbkdf2('sha256', $this->password, $salt, $count, length: 0, binary: true);
        $clientKey    = hash_hmac('sha256', data: 'Client Key', key: $salted, binary: true);
        $withoutProof = 'c=' . base64_encode(self::GS2_HEADER) . ",r={$nonce}";
        $authMessage  = "{$this->clientFirstBare},{$serverFirst},{$withoutProof}";
        $signature    = hash_hmac(
            'sha256',
            $authMessage,
            hash('sha256', $clientKey, binary: true),
            binary: true,
        );
        $serverKey             = hash_hmac('sha256', data: 'Server Key', key: $salted, binary: true);
        $this->serverSignature = hash_hmac('sha256', $authMessage, $serverKey, binary: true);

        return base64_encode("{$withoutProof},p=" . base64_encode($clientKey ^ $signature));
    }

    /**
     * Check the server-final message proves the server knows the password.
     *
     * @param string $expected The server signature respond() computed.
     * @param string $challenge The server-final message, in base64.
     * @throws RuntimeException When the server reports an error, or its signature is missing or does not match.
     */
    private function verify(string $expected, string $challenge): void
    {
        $serverFinal = self::decode($challenge);
        if (str_starts_with($serverFinal, 'e=')) {
            throw new RuntimeException(
                'The server refused the SCRAM-SHA-256 authentication: '
                    . SafeText::display(substr($serverFinal, offset: 2)),
            );
        }

        $matches = [];
        if (1 !== preg_match('/^v=([^,]*)/', $serverFinal, $matches)) {
            throw new RuntimeException('The server sent an invalid SCRAM-SHA-256 final message');
        }

        if (! hash_equals($expected, (string) base64_decode($matches[1] ?? '', strict: true))) {
            throw new RuntimeException(
                "The server's SCRAM-SHA-256 signature does not match: it does not know the password",
            );
        }

        $this->verified = true;
    }

    /**
     * @return array{password: string}
     */
    public function __debugInfo(): array
    {
        return ['password' => Credentials::HIDDEN];
    }

    /**
     * 24 random bytes in base64: 32 characters, none of them ",".
     *
     * @throws RuntimeException
     */
    private static function randomNonce(): string
    {
        try {
            return base64_encode(random_bytes(24));

            // @codeCoverageIgnoreStart
            // Unreachable on supported systems, which always have a source of randomness
        } catch (RandomException $e) {
            throw new RuntimeException('No source of randomness for the SCRAM-SHA-256 nonce', 0, $e);
        }

        // @codeCoverageIgnoreEnd
    }

    /**
     * @throws RuntimeException
     */
    private static function decode(string $challenge): string
    {
        $decoded = base64_decode($challenge, strict: true);
        if (false === $decoded) {
            throw new RuntimeException('The server sent a SCRAM-SHA-256 challenge that is not base64');
        }

        return $decoded;
    }
}
