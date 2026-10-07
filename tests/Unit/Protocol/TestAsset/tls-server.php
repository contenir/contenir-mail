<?php

/**
 * A one-connection TLS server on the loopback interface, with a certificate
 * made up on the spot, for StreamConnectionTest and SmtpSocketTest.
 *
 * Usage: php tls-server.php implicit|starttls|smtp
 *
 * Prints the port it listens on, then serves one client: "implicit"
 * negotiates TLS at once, "starttls" sends "OK" in plain text, reads one
 * line and then negotiates TLS. Once secure it sends "secure" and waits for
 * the client to close the connection. "smtp" greets, offers STARTTLS in its
 * EHLO reply, negotiates TLS after STARTTLS, and then answers EHLO with 250
 * and QUIT with 221.
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

$mode = $argv[1] ?? '';
if ('starttls' === $mode) {
    fwrite($client, data: "OK\r\n");
    fgets($client);
}

if ('smtp' === $mode) {
    fwrite($client, data: "220 localhost ESMTP\r\n");
    fgets($client);
    fwrite($client, data: "250-localhost\r\n250 STARTTLS\r\n");
    fgets($client);
    fwrite($client, data: "220 Ready to start TLS\r\n");
}

if (true !== stream_socket_enable_crypto($client, enable: true, crypto_method: STREAM_CRYPTO_METHOD_TLS_SERVER)) {
    exit(0);
}

if ('smtp' !== $mode) {
    fwrite($client, data: "secure\r\n");
    stream_get_contents($client);
    exit(0);
}

while (false !== ($line = fgets($client))) {
    if (str_starts_with($line, 'QUIT')) {
        fwrite($client, data: "221 Bye\r\n");
        exit(0);
    }

    fwrite($client, data: "250 localhost\r\n");
}
