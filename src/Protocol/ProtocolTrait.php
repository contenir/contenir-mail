<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use Contenir\Mail\Exception\InvalidArgumentException as MailInvalidArgumentException;

/**
 * Certificate verification settings shared by the protocols, and the socket
 * setup SMTP still uses until it opens a Connection of its own.
 *
 * @api
 */
trait ProtocolTrait
{
    /**
     * If set to true, do not validate the TLS certificate
     */
    protected bool $novalidatecert = false;

    /**
     * The TLS versions offered: 1.2 and 1.3 only, as RFC 8996 deprecates 1.0 and 1.1.
     */
    public function getCryptoMethod(): int
    {
        return StreamConnection::CRYPTO_METHOD;
    }

    /**
     * Do not validate the TLS certificate. Only for test servers: without
     * validation anyone on the network path can read and change the session.
     *
     * @param bool $novalidatecert Set to true to disable certificate validation
     */
    public function setNoValidateCert(bool $novalidatecert): static
    {
        $this->novalidatecert = $novalidatecert;

        return $this;
    }

    /**
     * Should we validate the TLS certificate?
     */
    public function validateCert(): bool
    {
        return ! $this->novalidatecert;
    }

    /**
     * Open a socket to the server, verifying its certificate unless told not to.
     *
     * @param string $transport "ssl" for TLS from the start, anything else for a plain connection
     * @param int|null $port null fails, as no port can be guessed here
     * @param int $timeout timeout in seconds for initiating the session and for each read
     * @return resource The socket created.
     * @throws Exception\RuntimeException If unable to connect to host.
     * @throws MailInvalidArgumentException When the timeout is below one second.
     */
    protected function setupSocket(string $transport, string $host, ?int $port, int $timeout): mixed
    {
        $connection = new StreamConnection();
        $connection->open(
            new ConnectionConfig(
                host: $host,
                security: 'ssl' === $transport ? Security::Tls : Security::None,
                verifyPeer: ! $this->novalidatecert,
                timeout: $timeout,
            ),
            $port ?? 0,
        );

        return $connection->detach();
    }
}
