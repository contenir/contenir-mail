<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp\Auth;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Override;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function hash_hmac;

/**
 * AUTH CRAM-MD5 (RFC 2195): answers the server's challenge with an HMAC-MD5 of the password.
 *
 * The password never crosses the wire, but MD5 is weak and the server must store
 * the password in a recoverable form. Prefer PLAIN or LOGIN over TLS where offered.
 *
 * @api
 */
final readonly class CramMd5 implements AuthenticatorInterface
{
    public const array KEYS = ['username', 'password'];

    private string $password;

    /**
     * @throws InvalidArgumentException When either value is empty or the username contains a control character.
     */
    public function __construct(
        public string $username,
        #[SensitiveParameter]
        string $password,
    ) {
        Credentials::username('CRAM-MD5', $username);
        $this->password = Credentials::secret('CRAM-MD5', 'a password', $password);
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
        return 'CRAM-MD5';
    }

    /**
     * @throws RuntimeException When the challenge is not base64 text.
     */
    #[Override]
    public function authenticate(ChannelInterface $channel): void
    {
        $challenge = base64_decode($channel->exchange('AUTH CRAM-MD5', 334), strict: true);
        if (false === $challenge || '' === $challenge) {
            throw new RuntimeException('The server sent an invalid CRAM-MD5 challenge');
        }

        $digest = hash_hmac('md5', $challenge, $this->password);
        $channel->exchangeSecret(base64_encode("{$this->username} {$digest}"), 235);
    }

    /**
     * @return array{username: string, password: string}
     */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'password' => Credentials::HIDDEN];
    }
}
