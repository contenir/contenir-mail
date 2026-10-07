<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp\Auth;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Override;
use SensitiveParameter;

use function base64_encode;
use function str_contains;

/**
 * AUTH PLAIN (RFC 4616): the username and password in one base64 response.
 *
 * Sends the password itself, so use it only over TLS.
 */
final readonly class Plain implements AuthenticatorInterface
{
    public const array KEYS = ['username', 'password'];

    private string $password;

    /**
     * @throws InvalidArgumentException When either value is empty, the username contains a control
     *     character, or the password contains NUL, which separates the fields.
     */
    public function __construct(
        public string $username,
        #[SensitiveParameter]
        string $password,
    ) {
        Credentials::username('PLAIN', $username);
        if (str_contains($password, "\0")) {
            throw new InvalidArgumentException('The PLAIN password must not contain NUL');
        }

        $this->password = Credentials::secret('PLAIN', 'a password', $password);
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
        return 'PLAIN';
    }

    #[Override]
    public function authenticate(ChannelInterface $channel): void
    {
        $channel->exchange('AUTH PLAIN', 334);
        $channel->exchangeSecret(base64_encode("\0{$this->username}\0{$this->password}"), 235);
    }

    /**
     * @return array{username: string, password: string}
     */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'password' => Credentials::HIDDEN];
    }
}
