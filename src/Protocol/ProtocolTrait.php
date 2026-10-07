<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

/**
 * Certificate verification settings shared by the protocols.
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
}
