<?php

/**
 * A one-connection TLS server on 127.0.0.1 for the STARTTLS tests.
 *
 * Usage: php tls-server.php <certificate-and-key.pem>
 * Prints the port, accepts one connection, completes the TLS handshake if the client
 * starts one, and exits when the client closes the connection or after ten seconds.
 */

declare(strict_types=1);

$context = stream_context_create(['ssl' => ['local_cert' => $argv[1] ?? '']]);
$server  = stream_socket_server('tcp://127.0.0.1:0', context: $context);
if (false === $server) {
    exit(1);
}

$name = (string) stream_socket_get_name($server, remote: false);
echo substr($name, (int) strrpos($name, needle: ':') + 1), "\n";

$client = stream_socket_accept($server, timeout: 10);
if (false === $client) {
    exit(1);
}

set_error_handler(static fn(): bool => true);
stream_socket_enable_crypto($client, enable: true, crypto_method: STREAM_CRYPTO_METHOD_TLS_SERVER);
stream_set_timeout($client, seconds: 10);
fread($client, length: 1);
