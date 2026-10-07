<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\ConnectionConfig;
use SensitiveParameter;

/**
 * Settings of a POP3 mailbox: where to connect and who to log in as.
 *
 * Connections use STARTTLS (STLS) unless "security" (or the laminas-mail "ssl") says otherwise.
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
    /** ConnectionConfig::KEYS, the laminas-mail "ssl" and "novalidatecert", and the login */
    public const array KEYS = [
        'host',
        'port',
        'security',
        'verify_peer',
        'timeout',
        'ssl',
        'novalidatecert',
        'user',
        'password',
    ];

    /** Shown instead of the password */
    private const string MASK = '********';

    public function __construct(
        public ConnectionConfig $connection,
        public string $user,
        #[SensitiveParameter]
        public string $password = '',
    ) {}

    /**
     * @param iterable<mixed, mixed> $config
     * @throws InvalidArgumentException When a key is unknown or missing, or a value has the wrong type.
     */
    public static function fromIterable(#[SensitiveParameter] iterable $config): self
    {
        $reader = ConfigReader::read(self::class, $config, self::KEYS);

        return new self(
            connection: RemoteConnection::fromReader($reader, self::class),
            user: $reader->requiredString('user'),
            password: $reader->string('password', default: ''),
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
        ];
    }
}
