<?php

/**
 * A one-connection TLS server on the loopback interface, with a certificate
 * made up on the spot, for StreamConnectionTest.
 *
 * Usage: php tls-server.php implicit|starttls
 *
 * Prints the port it listens on, then serves one client: "implicit"
 * negotiates TLS at once, "starttls" sends "OK" in plain text, reads one
 * line and then negotiates TLS. Once secure it sends "secure" and waits for
 * the client to close the connection.
 */

declare(strict_types=1);

set_error_handler(static fn(): bool => true);

$key         = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$request     = openssl_csr_new(['commonName' => 'localhost'], $key);
$certificate = openssl_csr_sign($request, ca_certificate: null, private_key: $key, days: 1);
openssl_x509_export($certificate, $pem);
openssl_pkey_export($key, $keyPem);

$file = tempnam(sys_get_temp_dir(), prefix: 'contenir-tls-');
file_put_contents($file, $pem . $keyPem);
register_shutdown_function(static fn(): bool => unlink($file));

$context = stream_context_create(['ssl' => [
    'local_cert'    => $file,
    'crypto_method' => STREAM_CRYPTO_METHOD_TLS_SERVER,
]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, context: $context);
$name   = stream_socket_get_name($server, remote: false);
fwrite(STDOUT, substr($name, strrpos($name, needle: ':') + 1) . "\n");

$client = stream_socket_accept($server, timeout: 10);
if (false === $client) {
    exit(1);
}

if ('starttls' === ($argv[1] ?? '')) {
    fwrite($client, data: "OK\r\n");
    fgets($client);
}

if (true !== stream_socket_enable_crypto($client, enable: true, crypto_method: STREAM_CRYPTO_METHOD_TLS_SERVER)) {
    exit(0);
}

fwrite($client, data: "secure\r\n");
stream_get_contents($client);
