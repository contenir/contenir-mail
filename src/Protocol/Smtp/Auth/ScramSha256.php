<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp\Auth;

use Closure;
use Contenir\Mail\ConfigReader;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Sasl\SaslPrep;
use Contenir\Mail\Protocol\Sasl\ScramSha256Exchange;
use Override;
use SensitiveParameter;

/**
 * AUTH SCRAM-SHA-256 (RFC 5802, RFC 7677): proves the password without sending it, and
 * checks the server's proof in return, failing closed when the server cannot give it.
 *
 * ```php
 * new ScramSha256('jo@example.com', $password);
 * ```
 *
 * IMAP and POP3 take the same authenticator, so the same settings sign in to all three protocols.
 *
 * Channel binding (SCRAM-SHA-256-PLUS) is not offered, as PHP does not expose the TLS session
 * data it needs, so the exchange does not prove which TLS connection it ran over; use TLS anyway.
 * A username or password outside printable ASCII is normalised to NFKC, which needs the intl
 * extension; see Protocol\Sasl\SaslPrep.
 */
final readonly class ScramSha256 implements AuthenticatorInterface
{
    public const array KEYS = ['username', 'password'];

    private string $password;

    /** @var (Closure(): string)|null */
    private ?Closure $nonce;

    /**
     * @param (Closure(): string)|null $nonce Makes each client nonce; random by default, given only in tests.
     * @throws InvalidArgumentException When either value is empty, the username contains a control
     *     character, or either cannot be prepared with SASLprep.
     */
    public function __construct(
        public string $username,
        #[SensitiveParameter]
        string $password,
        ?Closure $nonce = null,
    ) {
        $prep = new SaslPrep();
        $prep->prepare(Credentials::username(ScramSha256Exchange::MECHANISM, $username), 'username');
        $this->password = $prep->prepare(
            Credentials::secret(ScramSha256Exchange::MECHANISM, 'a password', $password),
            'password',
        );
        $this->nonce = $nonce;
    }

    /**
     * @param iterable<mixed, mixed> $config Keys "username" and "password".
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a key is unknown or a value is invalid.
     */
    public static function fromIterable(#[SensitiveParameter] iterable $config): self
    {
        $reader = ConfigReader::read(self::class, $config, self::KEYS);

        return new self($reader->string('username', ''), $reader->string('password', ''));
    }

    #[Override]
    public function mechanism(): string
    {
        return ScramSha256Exchange::MECHANISM;
    }

    /**
     * Start a new exchange, with a new client nonce. IMAP and POP3 carry it in their own commands.
     *
     * @throws InvalidArgumentException When the nonce Closure returns an invalid nonce.
     * @throws RuntimeException When the system has no source of randomness for the nonce.
     */
    public function start(): ScramSha256Exchange
    {
        return new ScramSha256Exchange(
            $this->username,
            $this->password,
            null === $this->nonce ? null : ($this->nonce)(),
        );
    }

    /**
     * Server-final arrives in a 334 challenge (RFC 4954, section 4) and is answered with an empty line.
     * A step the client refuses is cancelled with "*", so the session can go on.
     *
     * @throws RuntimeException When the server refuses the credentials or cannot prove it knows the password.
     */
    #[Override]
    public function authenticate(ChannelInterface $channel): void
    {
        $exchange = $this->start();
        $channel->exchange('AUTH ' . ScramSha256Exchange::MECHANISM, 334);
        $challenge = $channel->exchangeSecret($exchange->initialResponse(), 334);
        try {
            $response = $exchange->respond($challenge);
        } catch (RuntimeException $e) {
            self::cancel($channel, $e);
        }

        try {
            $final = $channel->exchangeSecret($response, 334);
        } catch (RuntimeException $e) {
            if (235 !== $e->getCode()) {
                throw $e;
            }

            throw new RuntimeException(
                'The server accepted SCRAM-SHA-256 without proving it knows the password',
                previous: $e,
            );
        }

        try {
            $exchange->verify($final);
        } catch (RuntimeException $e) {
            self::cancel($channel, $e);
        }

        $channel->exchange('', 235);
    }

    /**
     * @return array{username: string, password: string}
     */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'password' => Credentials::HIDDEN];
    }

    /**
     * End the exchange with "*", which the server answers with 501 (RFC 4954, section 4), then throw $reason.
     *
     * @throws RuntimeException
     */
    private static function cancel(ChannelInterface $channel, RuntimeException $reason): never
    {
        try {
            $channel->exchange('*', 501);
        } catch (RuntimeException $e) {
            throw new RuntimeException($reason->getMessage(), previous: $e);
        }

        throw $reason;
    }
}
