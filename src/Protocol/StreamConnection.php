<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use Override;

use function fclose;
use function fgets;
use function fread;
use function fwrite;
use function get_resource_type;
use function is_resource;
use function sprintf;
use function str_contains;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_select;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function strlen;
use function substr;

use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
use const STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

/**
 * A Connection over a PHP stream socket.
 *
 * Peers are verified unless the configuration turns verification off, and
 * only TLS 1.2 and 1.3 are offered.
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity Each ConnectionInterface method checks its stream for errors and timeouts.
 * @mago-expect lint:too-many-methods The ConnectionInterface methods and their stream checks.
 */
final class StreamConnection implements ConnectionInterface
{
    /** The TLS versions offered, for implicit TLS and for STARTTLS alike */
    public const int CRYPTO_METHOD = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    /** @var resource|null */
    private mixed $stream = null;

    private string $peer = 'the server';

    /** Seconds to wait for each read and write; PHP applies its stream timeout to reads only */
    private int $timeout = 30;

    /**
     * Wrap a stream that is already open, such as one a protocol opened itself.
     *
     * @param resource $stream
     * @throws Exception\InvalidArgumentException When $stream is not an open stream.
     */
    public static function fromStream(mixed $stream, string $peer = 'the server'): self
    {
        if (! is_resource($stream) || 'stream' !== get_resource_type($stream)) {
            throw new Exception\InvalidArgumentException('Expected an open stream');
        }

        $connection         = new self();
        $connection->stream = $stream;
        $connection->peer   = $peer;

        return $connection;
    }

    #[Override]
    public function open(ConnectionConfig $config, int $port): void
    {
        $this->close();

        $host       = str_contains($config->host, ':') ? "[{$config->host}]" : $config->host;
        $transport  = Security::Tls === $config->security ? 'ssl' : 'tcp';
        $this->peer = "{$host}:{$port}";
        $context    = stream_context_create([
            'ssl' => [
                'verify_peer'      => $config->verifyPeer,
                'verify_peer_name' => $config->verifyPeer,
                'crypto_method'    => self::CRYPTO_METHOD,
            ],
        ]);

        [$stream, $warning] = ErrorCapture::run(static fn(): mixed => stream_socket_client(
            "{$transport}://{$host}:{$port}",
            timeout: $config->timeout,
            flags: STREAM_CLIENT_CONNECT,
            context: $context,
        ));
        if (! is_resource($stream)) {
            throw new Exception\RuntimeException("Cannot connect to {$this->peer}: {$warning}");
        }

        stream_set_timeout($stream, $config->timeout);
        $this->stream  = $stream;
        $this->timeout = $config->timeout;
    }

    #[Override]
    public function isConnected(): bool
    {
        return is_resource($this->stream);
    }

    #[Override]
    public function write(string $data): void
    {
        $stream = $this->stream();

        stream_set_blocking($stream, enable: false);
        try {
            while ('' !== $data) {
                $this->awaitWritable($stream);
                [$written, $warning] = ErrorCapture::run(static fn(): int|false => fwrite($stream, $data));
                if (false === $written) {
                    throw new Exception\RuntimeException(sprintf('Cannot write to %s: %s', $this->peer, $warning));
                }

                $data = substr($data, $written);
            }
        } finally {
            stream_set_blocking($stream, enable: true);
        }
    }

    #[Override]
    public function readLine(int $maxLength): string
    {
        $stream = $this->stream();

        [$line, $warning] = ErrorCapture::run(static fn(): string|false => fgets($stream, $maxLength + 1));
        $this->throwIfTimedOut($stream);
        if (false === $line) {
            throw $this->closed($warning);
        }

        return $line;
    }

    #[Override]
    public function read(int $length): string
    {
        $stream = $this->stream();

        $data = '';
        while (strlen($data) < $length) {
            [$chunk, $warning] = ErrorCapture::run(static fn(): string|false => fread(
                $stream,
                $length - strlen($data),
            ));
            $this->throwIfTimedOut($stream);
            if (false === $chunk || '' === $chunk) {
                throw $this->closed($warning);
            }

            $data .= $chunk;
        }

        return $data;
    }

    #[Override]
    public function enableTls(): void
    {
        $stream = $this->stream();

        if (stream_get_meta_data($stream)['unread_bytes'] > 0) {
            throw new Exception\RuntimeException(sprintf(
                '%s sent data before TLS was negotiated; refusing to continue',
                $this->peer,
            ));
        }

        [$enabled, $warning] = ErrorCapture::run(
            static fn(): bool|int => stream_socket_enable_crypto(
                $stream,
                enable: true,
                crypto_method: self::CRYPTO_METHOD,
            ),
        );
        if (true !== $enabled) {
            throw new Exception\RuntimeException(sprintf('Cannot enable TLS with %s: %s', $this->peer, $warning));
        }
    }

    /**
     * @throws Exception\RuntimeException When the connection is not open.
     */
    #[Override]
    public function setTimeout(int $seconds): void
    {
        stream_set_timeout($this->stream(), $seconds);
        $this->timeout = $seconds;
    }

    #[Override]
    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    /**
     * @return resource
     * @throws Exception\RuntimeException When the connection is not open.
     */
    private function stream(): mixed
    {
        if (! is_resource($this->stream)) {
            throw new Exception\RuntimeException("No connection has been established to {$this->peer}");
        }

        return $this->stream;
    }

    /**
     * Wait until the stream takes more bytes: a server that stops reading must not block a write forever.
     *
     * Only sockets can be waited on; other streams, such as php://memory, never block a write.
     *
     * @param resource $stream
     * @throws Exception\TimeoutException When the stream does not become writable within the timeout.
     */
    private function awaitWritable(mixed $stream): void
    {
        if (! str_contains(stream_get_meta_data($stream)['stream_type'], 'socket')) {
            return;
        }

        $read   = null;
        $write  = [$stream];
        $except = null;
        [$ready] = ErrorCapture::run(fn(): int|false => stream_select($read, $write, $except, $this->timeout));

        if (0 === $ready) {
            throw new Exception\TimeoutException("{$this->peer} has timed out");
        }
    }

    /**
     * @param resource $stream
     * @throws Exception\TimeoutException
     */
    private function throwIfTimedOut(mixed $stream): void
    {
        if (stream_get_meta_data($stream)['timed_out']) {
            throw new Exception\TimeoutException("{$this->peer} has timed out");
        }
    }

    private function closed(string $warning): Exception\RuntimeException
    {
        return new Exception\RuntimeException(sprintf(
            'Cannot read from %s: the connection is closed%s',
            $this->peer,
            '' === $warning ? '' : " ({$warning})",
        ));
    }
}
