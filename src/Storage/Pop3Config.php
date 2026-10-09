<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Smtp\Auth\ScramSha256;
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
use Contenir\Mail\Protocol\TlsConfig;
use SensitiveParameter;

/**
 * Settings of a POP3 mailbox: where to connect and who to log in as.
 *
 * Connections use STARTTLS (STLS) unless "security" (or the deprecated laminas-mail "ssl") says otherwise.
 *
 * ```php
 * new Pop3Config(new ConnectionConfig(host: 'pop.example.com', security: Security::Tls), user: 'test', password: $secret);
 * Pop3Config::fromIterable(['host' => 'pop.example.com', 'user' => 'test', 'password' => $secret]);
 * ```
 *
 * @api
 */
final readonly class Pop3Config
{
    /**
     * The connection settings, the deprecated laminas-mail "ssl" and "novalidatecert", the TLS settings, and the login
     *
     * @var list<string>
     */
    public const array KEYS = [
        'host',
        'port',
        'security',
        'verify_peer',
        'timeout',
        'ssl',
        'novalidatecert',
        ...TlsConfig::KEYS,
        'user',
        'password',
        'auth',
    ];

    /** Shown instead of the password */
    private const string MASK = '********';

    /**
     * @param XOAuth2|ScramSha256|null $auth How to sign in with SASL instead of the password: an OAuth 2.0
     *     access token (XOAUTH2), or a password proved without sending it (SCRAM-SHA-256).
     */
    public function __construct(
        public ConnectionConfig $connection,
        public string $user,
        #[SensitiveParameter]
        public string $password = '',
        public XOAuth2|ScramSha256|null $auth = null,
    ) {}

    /**
     * @param iterable<mixed, mixed> $config
     * @throws InvalidArgumentException When a key is unknown or missing, or a value has the wrong type.
     */
    public static function fromIterable(#[SensitiveParameter] iterable $config): self
    {
        $reader = ConfigReader::read(self::class, $config, self::KEYS);
        $auth   = RemoteAuth::fromReader($reader, self::class);

        return new self(
            connection: RemoteConnection::fromReader($reader, self::class),
            user: null === $auth ? $reader->requiredString('user') : $reader->string('user', default: $auth->username),
            password: $reader->string('password', default: ''),
            auth: $auth,
        );
    }

    /**
     * Shown by var_dump() and print_r(), with the password masked.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'connection' => $this->connection,
            'user'       => $this->user,
            'password'   => self::MASK,
            'auth'       => $this->auth,
        ];
    }
}
