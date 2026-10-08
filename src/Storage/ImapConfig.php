<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Smtp\Auth\ScramSha256;
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
use Contenir\Mail\Protocol\TlsOptions;
use SensitiveParameter;

/**
 * Settings of an IMAP mailbox: where to connect, who to log in as, and the folder to open.
 *
 * Connections use STARTTLS unless "security" (or the laminas-mail "ssl") says otherwise.
 *
 * ```php
 * new ImapConfig(new ConnectionConfig(host: 'imap.example.com', security: Security::Tls), user: 'test', password: $secret);
 * ImapConfig::fromIterable(['host' => 'imap.example.com', 'user' => 'test', 'password' => $secret]);
 * ```
 *
 * @api
 */
final readonly class ImapConfig
{
    /**
     * The connection settings, the laminas-mail "ssl" and "novalidatecert", the TLS settings, and the login
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
        ...TlsOptions::KEYS,
        'user',
        'password',
        'folder',
        'auth',
    ];

    /** Shown instead of the password */
    private const string MASK = '********';

    /**
     * @param string $folder The folder selected after logging in.
     * @param XOAuth2|ScramSha256|null $auth How to sign in with SASL instead of the password: an OAuth 2.0
     *     access token (XOAUTH2), or a password proved without sending it (SCRAM-SHA-256).
     * @throws Exception\InvalidArgumentException When the folder name holds a line break or NUL.
     */
    public function __construct(
        public ConnectionConfig $connection,
        public string $user,
        #[SensitiveParameter]
        public string $password = '',
        public string $folder = 'INBOX',
        public XOAuth2|ScramSha256|null $auth = null,
    ) {
        RemoteFolder::check($folder);
    }

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
            folder: $reader->string('folder', default: 'INBOX'),
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
            'folder'     => $this->folder,
            'auth'       => $this->auth,
        ];
    }
}
