<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp\Auth;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Override;
use SensitiveParameter;

use function base64_encode;

/**
 * AUTH LOGIN: the username and the password, each in its own base64 response.
 *
 * Sends the password itself, so use it only over TLS.
 *
 * @api
 */
final readonly class Login implements AuthenticatorInterface
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
        Credentials::username('LOGIN', $username);
        $this->password = Credentials::secret('LOGIN', 'a password', $password);
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
        return 'LOGIN';
    }

    #[Override]
    public function authenticate(ChannelInterface $channel): void
    {
        $channel->exchange('AUTH LOGIN', 334);
        $channel->exchangeSecret(base64_encode($this->username), 334);
        $channel->exchangeSecret(base64_encode($this->password), 235);
    }

    /**
     * @return array{username: string, password: string}
     */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'password' => Credentials::HIDDEN];
    }
}
