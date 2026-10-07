<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp\Auth\AuthenticatorFactory;
use Contenir\Mail\Protocol\Smtp\Auth\AuthenticatorInterface;
use Contenir\Mail\Validator\HostnameValidator;
use SensitiveParameter;

use function implode;

/**
 * Settings for the SMTP transport.
 *
 * ```php
 * new SmtpConfig(host: 'smtp.example.com', port: 587, auth: new Login('orders', $password));
 * SmtpConfig::fromIterable([
 *     'host' => 'smtp.example.com',
 *     'port' => '587',
 *     'auth' => ['type' => 'login', 'username' => 'orders', 'password' => $password],
 * ]);
 * ```
 *
 * STARTTLS is required unless "security" says otherwise: "tls" for TLS from the start
 * (port 465), or "none" for a plain connection to a local relay (port 25). Without a port,
 * STARTTLS connects to the submission port 587 (RFC 6409).
 *
 * @mago-expect lint:excessive-parameter-list Built with named arguments; every setting is optional.
 */
final readonly class SmtpConfig
{
    /** The one place the SMTP transport's default security is set */
    public const Security DEFAULT_SECURITY = Security::StartTls;

    /** @var list<string> */
    public const array KEYS = [
        ...ConnectionConfig::KEYS,
        'name',
        'auth',
        'allow_insecure_auth',
        'connection_time_limit',
        'use_complete_quit',
    ];

    /** Where and how to connect */
    public ConnectionConfig $connection;

    /**
     * @param string $name The client's own host name, sent with EHLO.
     * @param AuthenticatorInterface|null $auth How to log in, if the server requires it.
     * @param bool $allowInsecureAuth Send credentials over an unencrypted connection; off by default.
     * @param int|null $connectionTimeLimit Seconds after which the transport reconnects rather than
     *     reusing the connection; when set, QUIT is not sent.
     * @param bool $useCompleteQuit Send QUIT before closing the connection.
     * @throws InvalidArgumentException When a value is out of range, or credentials would be sent unencrypted.
     */
    public function __construct(
        string $host = '127.0.0.1',
        ?int $port = null,
        Security $security = self::DEFAULT_SECURITY,
        bool $verifyPeer = true,
        int $timeout = 30,
        public string $name = 'localhost',
        public ?AuthenticatorInterface $auth = null,
        public bool $allowInsecureAuth = false,
        public ?int $connectionTimeLimit = null,
        public bool $useCompleteQuit = true,
    ) {
        $this->connection = new ConnectionConfig($host, $port, $security, $verifyPeer, $timeout);

        $validator = HostnameValidator::forConnection();
        if (! $validator->isValid($name)) {
            throw new InvalidArgumentException(
                'SMTP client name "' . $name . '" is invalid: ' . implode(', ', $validator->getMessages()),
            );
        }

        if (null !== $connectionTimeLimit && $connectionTimeLimit < 1) {
            throw new InvalidArgumentException(
                "Connection time limit {$connectionTimeLimit} must be at least one second",
            );
        }

        if (null !== $auth && Security::None === $security && ! $allowInsecureAuth) {
            throw new InvalidArgumentException(
                'SMTP authentication over an unencrypted connection would expose the credentials; '
                    . 'use security "starttls" or "tls", or set allow_insecure_auth',
            );
        }
    }

    /**
     * @param iterable<mixed, mixed> $config The keys in KEYS; "auth" takes an authenticator or
     *     settings such as `['type' => 'login', 'username' => …, 'password' => …]`.
     * @throws InvalidArgumentException When a key is unknown or a value is invalid.
     */
    public static function fromIterable(#[SensitiveParameter] iterable $config): self
    {
        $reader = ConfigReader::read(self::class, $config, self::KEYS);

        return new self(
            host: $reader->string('host', default: '127.0.0.1'),
            port: $reader->nullableInt('port'),
            security: $reader->enum('security', default: self::DEFAULT_SECURITY),
            verifyPeer: $reader->bool('verify_peer', default: true),
            timeout: $reader->int('timeout', default: 30),
            name: $reader->string('name', default: 'localhost'),
            auth: $reader->section('auth', AuthenticatorInterface::class, AuthenticatorFactory::fromIterable(...)),
            allowInsecureAuth: $reader->bool('allow_insecure_auth', default: false),
            connectionTimeLimit: $reader->nullableInt('connection_time_limit'),
            useCompleteQuit: $reader->bool('use_complete_quit', default: true),
        );
    }
}
