<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Sasl;

use Closure;
use Contenir\Mail\ConfigReader;
use Contenir\Mail\Protocol\Exception\ExceptionInterface;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Smtp\Auth\AuthenticatorInterface;
use Contenir\Mail\Protocol\Smtp\Auth\ChannelInterface;
use Contenir\Mail\Protocol\Smtp\Auth\Credentials;
use Contenir\Mail\Protocol\Smtp\Auth\SaslAuthenticator;
use Contenir\Mail\Protocol\Xoauth2\Xoauth2 as Encoder;
use Override;
use SensitiveParameter;

use function get_debug_type;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * XOAUTH2, as Google and Microsoft use it: an OAuth 2.0 bearer token for the mailbox,
 * to sign in to IMAP, POP3 and SMTP.
 *
 * ```php
 * $imap->authenticate(new Xoauth2('jo@example.com', $accessToken));
 * new SmtpConfig(host: 'smtp.example.com', auth: new Xoauth2('jo@example.com', static fn(): string => $tokens->fresh()));
 * ```
 *
 * Give a Closure instead of the token when it expires during the life of the connection,
 * as in a long-running worker: it is called for a fresh token at each sign-in.
 *
 * The token is a credential, so use it only over TLS.
 *
 * Not final only so the deprecated Protocol\Smtp\Auth\XOAuth2 can extend it; do not extend it.
 *
 * @api
 */
readonly class Xoauth2 implements MechanismInterface, AuthenticatorInterface
{
    public const array KEYS = ['username', 'access_token'];

    private string|Closure $accessToken;

    /**
     * @param string $username The mailbox the token was issued for.
     * @param string|Closure $accessToken The token, or a Closure that returns it, called at each sign-in.
     * @throws InvalidArgumentException When the username or token is empty or contains a control character,
     *     which would let it rewrite the fields of the SASL message.
     */
    public function __construct(
        public string $username,
        #[SensitiveParameter]
        string|Closure $accessToken,
    ) {
        Credentials::username('XOAUTH2', $username);
        $this->accessToken = $accessToken instanceof Closure ? $accessToken : self::checkToken($accessToken);
    }

    /**
     * @param iterable<mixed, mixed> $config Keys "username" and "access_token", the token as a string
     *     or as a callable that returns it.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a key is unknown or a value is invalid.
     */
    public static function fromIterable(#[SensitiveParameter] iterable $config): self
    {
        $reader = ConfigReader::read(self::class, $config, self::KEYS);

        return new self($reader->string('username', ''), $reader->stringOrCallable('access_token') ?? '');
    }

    #[Override]
    public function mechanism(): string
    {
        return 'XOAUTH2';
    }

    /**
     * A new exchange, with the token taken from the provider when there is one.
     *
     * @throws InvalidArgumentException When a token provider returns an empty token or one with a control character.
     * @throws RuntimeException When a token provider returns something other than a string.
     */
    #[Override]
    public function start(): ExchangeInterface
    {
        return new Xoauth2Exchange($this->initialResponse());
    }

    /**
     * Run AUTH XOAUTH2 over SMTP. A refused token is answered with the empty response that
     * ends the exchange (RFC 7628, section 3.2.3) before this throws.
     *
     * @throws InvalidArgumentException When a token provider returns an empty token or one with a control character.
     * @throws RuntimeException When a token provider returns something other than a string, or the server
     *     refuses the token.
     * @throws ExceptionInterface When the connection fails.
     */
    #[Override]
    public function authenticate(ChannelInterface $channel): void
    {
        (new SaslAuthenticator($this))->authenticate($channel);
    }

    /**
     * The base64 SASL response that carries the token, taken from the provider when there is one.
     *
     * @throws InvalidArgumentException When a token provider returns an empty token or one with a control character.
     * @throws RuntimeException When a token provider returns something other than a string.
     */
    public function initialResponse(): string
    {
        $token = $this->accessToken instanceof Closure ? self::provide($this->accessToken) : $this->accessToken;

        return Encoder::encodeXoauth2Sasl($this->username, $token);
    }

    /**
     * @return array{username: string, accessToken: string}
     */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'accessToken' => Credentials::HIDDEN];
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @mago-expect analysis:mixed-assignment A Closure's return type is not enforced, so it is checked here.
     */
    private static function provide(Closure $provider): string
    {
        $token = $provider();
        if (! is_string($token)) {
            throw new RuntimeException(sprintf(
                'The XOAUTH2 access token provider must return a string, got %s',
                get_debug_type($token),
            ));
        }

        return self::checkToken($token);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function checkToken(#[SensitiveParameter] string $token): string
    {
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $token)) {
            throw new InvalidArgumentException('The XOAUTH2 access token must not contain control characters');
        }

        return Credentials::secret('XOAUTH2', 'an access token', $token);
    }
}
