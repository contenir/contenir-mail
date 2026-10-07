<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp\Auth;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Xoauth2\Xoauth2 as Encoder;
use Override;
use SensitiveParameter;

use function preg_match;

/**
 * AUTH XOAUTH2, as used by Google and Microsoft: an OAuth 2.0 bearer token for the mailbox.
 *
 * The token is a credential, so use it only over TLS.
 */
final readonly class XOAuth2 implements AuthenticatorInterface
{
    public const array KEYS = ['username', 'access_token'];

    private string $accessToken;

    /**
     * @param string $username The mailbox the token was issued for.
     * @throws InvalidArgumentException When either value is empty or contains a control character,
     *     which would let it rewrite the fields of the SASL message.
     */
    public function __construct(
        public string $username,
        #[SensitiveParameter]
        string $accessToken,
    ) {
        Credentials::username('XOAUTH2', $username);
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $accessToken)) {
            throw new InvalidArgumentException('The XOAUTH2 access token must not contain control characters');
        }

        $this->accessToken = Credentials::secret('XOAUTH2', 'an access token', $accessToken);
    }

    /**
     * @param iterable<mixed, mixed> $config Keys "username" and "access_token".
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a key is unknown or a value is invalid.
     */
    public static function fromIterable(#[SensitiveParameter] iterable $config): self
    {
        $reader = ConfigReader::read(self::class, $config, self::KEYS);

        return new self($reader->string('username', ''), $reader->string('access_token', ''));
    }

    #[Override]
    public function mechanism(): string
    {
        return 'XOAUTH2';
    }

    #[Override]
    public function authenticate(ChannelInterface $channel): void
    {
        $channel->exchange('AUTH XOAUTH2', 334);
        $channel->exchangeSecret(Encoder::encodeXoauth2Sasl($this->username, $this->accessToken), 235);
    }

    /**
     * @return array{username: string, accessToken: string}
     */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'accessToken' => Credentials::HIDDEN];
    }
}
