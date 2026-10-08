<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use SensitiveParameter;

/**
 * A byte stream to a mail server, as IMAP, POP3 and SMTP use it.
 *
 * The protocols build commands and parse responses; a Connection only moves
 * bytes. StreamConnection talks to a real server, and Testing\InMemoryConnection
 * plays a scripted server for tests.
 *
 * Reads never return more than the caller allows, so a protocol can bound
 * what a hostile server makes it hold in memory.
 *
 * @api
 */
interface ConnectionInterface
{
    /**
     * Connect to the configured host on the given port.
     *
     * With Security::Tls, TLS is negotiated before this returns. With
     * Security::StartTls the connection starts in plain text, and the
     * protocol calls enableTls() after its STARTTLS or STLS command.
     *
     * @throws Exception\RuntimeException When the connection cannot be made.
     */
    public function open(ConnectionConfig $config, int $port): void;

    public function isConnected(): bool;

    /**
     * Send the bytes as they are, all of them.
     *
     * @throws Exception\RuntimeException When the connection is closed or the bytes cannot be sent.
     */
    public function write(#[SensitiveParameter] string $data): void;

    /**
     * Read the next line, its line feed included.
     *
     * A longer line comes back in pieces of $maxLength bytes, the way fgets()
     * returns it, so the caller decides whether that is an error. A final line
     * without a line feed is returned when the server closes the connection.
     *
     * @throws Exception\TimeoutException When the server sends nothing within the timeout.
     * @throws Exception\RuntimeException When the connection is closed.
     */
    public function readLine(int $maxLength): string;

    /**
     * Wait at most $seconds for the server to send something, without reading it.
     *
     * A quiet server is not an error here, as it is for the reads: this is how
     * a client waits for news, as with IMAP IDLE. True when bytes can be read,
     * or when the server has closed the connection, which the next read reports.
     *
     * @throws Exception\RuntimeException When the connection is not open.
     */
    public function waitUntilReadable(int $seconds): bool;

    /**
     * Read exactly $length bytes, as for an IMAP literal.
     *
     * @throws Exception\TimeoutException When the server sends nothing within the timeout.
     * @throws Exception\RuntimeException When the connection closes before $length bytes arrive.
     */
    public function read(int $length): string;

    /**
     * Negotiate TLS on the open connection, after STARTTLS or STLS.
     *
     * Peer verification follows the configuration given to open(). Fails if
     * the server has already sent bytes that were not read, since they arrived
     * in plain text and could have been injected.
     *
     * @throws Exception\RuntimeException When TLS cannot be negotiated; the connection must not be used in plain text.
     */
    public function enableTls(): void;

    /**
     * Wait at most this many seconds for each read and write from now on.
     *
     * @throws Exception\RuntimeException When the connection is not open.
     */
    public function setTimeout(int $seconds): void;

    /**
     * Close the connection; closing a closed connection does nothing.
     */
    public function close(): void;
}
