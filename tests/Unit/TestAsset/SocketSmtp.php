<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

use Contenir\Mail\Protocol\Smtp;

/**
 * Protocol\Smtp on a socket the test supplies, such as one end of stream_socket_pair(),
 * to exercise the TLS upgrade without a server.
 */
final class SocketSmtp extends Smtp
{
    /**
     * @param resource $socket
     */
    public function useSocket($socket): void
    {
        $this->socket = $socket;
    }

    /**
     * Read one reply, as helo() does before asking for STARTTLS.
     */
    public function readReply(int $code): string
    {
        return $this->_expect($code);
    }

    public function upgradeToTls(): void
    {
        $this->enableCrypto();
    }
}
