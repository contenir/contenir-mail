<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;

/**
 * Where and how to connect to a mail server, shared by SMTP, IMAP and POP3.
 *
 * ```php
 * new ConnectionConfig(host: 'mail.example.com', security: Security::StartTls);
 * ConnectionConfig::fromIterable(['host' => 'mail.example.com', 'port' => '587', 'security' => 'starttls']);
 * ```
 */
final readonly class ConnectionConfig
{
    public const array KEYS = ['host', 'port', 'security', 'verify_peer', 'timeout'];

    /**
     * @param int|null $port Null for the protocol's standard port for this security.
     * @param Security $security STARTTLS is required by default, so a server that cannot offer TLS
     *     is refused; set Security::None explicitly for a local relay without TLS.
     * @param int $timeout Seconds to wait for the connection and for each response.
     * @throws InvalidArgumentException When the port or timeout is out of range.
     */
    public function __construct(
        public string $host = '127.0.0.1',
        public ?int $port = null,
        public Security $security = Security::StartTls,
        public bool $verifyPeer = true,
        public int $timeout = 30,
    ) {
        if (null !== $port && ($port < 1 || $port > 65_535)) {
            throw new InvalidArgumentException("Port {$port} is out of range");
        }

        if ($timeout < 1) {
            throw new InvalidArgumentException("Timeout {$timeout} must be at least one second");
        }
    }

    /**
     * @param iterable<mixed, mixed> $config
     * @throws InvalidArgumentException When a key is unknown or a value has the wrong type.
     */
    public static function fromIterable(iterable $config): self
    {
        return self::fromReader(ConfigReader::read(self::class, $config, self::KEYS));
    }

    /**
     * Read the connection settings from a reader that also holds other keys, such as an SmtpConfig's.
     *
     * @internal
     * @throws InvalidArgumentException When a value has the wrong type.
     */
    public static function fromReader(ConfigReader $reader): self
    {
        return new self(
            host: $reader->string('host', default: '127.0.0.1'),
            port: $reader->nullableInt('port'),
            security: $reader->enum('security', default: Security::StartTls),
            verifyPeer: $reader->bool('verify_peer', default: true),
            timeout: $reader->int('timeout', default: 30),
        );
    }

    /**
     * The port to connect to: the configured one, or the standard one for the protocol and security.
     *
     * @param int $plain The port for a plain connection, and for STARTTLS unless $startTls is given.
     * @param int $tls The port for TLS from the start.
     * @param int|null $startTls The port for STARTTLS where it differs, as SMTP submission's 587 does.
     */
    public function portOr(int $plain, int $tls, ?int $startTls = null): int
    {
        return (
            $this->port ?? match ($this->security) {
                Security::Tls      => $tls,
                Security::StartTls => $startTls ?? $plain,
                Security::None     => $plain,
            }
        );
    }
}
