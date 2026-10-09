# SMTP Transport Options

`Contenir\Mail\Transport\Smtp` keeps its settings in a
`Contenir\Mail\Transport\SmtpConfig`. Build one with named arguments, or give the
transport an array with the same settings in snake_case.

```php
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Transport\Smtp;
use Contenir\Mail\Transport\SmtpConfig;

$transport = new Smtp(new SmtpConfig(
    host: 'smtp.example.com',
    port: 587,
    security: Security::StartTls,
    name: 'app.example.com',
));

$transport = new Smtp(SmtpConfig::fromIterable([
    'host'     => 'smtp.example.com',
    'port'     => '587',
    'security' => 'starttls',
    'name'     => 'app.example.com',
]));
```

## Settings

Key                     | Argument              | Default        | Meaning
----------------------- | --------------------- | -------------- | -------
`host`                  | `host`                | `127.0.0.1`    | The server's host name or address.
`port`                  | `port`                | 587, 465 for `tls`, 25 for `none` | The server's port.
`security`              | `security`            | `starttls`     | `starttls`: upgrade a plain connection, and refuse a server that cannot. `tls`: TLS from the start. `none`: no encryption.
`verify_peer`           | `verifyPeer`          | `true`         | Verify the server's certificate and name. Turn it off only for a test server.
`timeout`               | `timeout`             | `30`           | Seconds to wait for the connection.
`cafile`, `capath`, `peer_name`, `allow_self_signed`, `local_cert`, `local_pk`, `passphrase` | `tls: new TlsConfig(…)` | none | Trust a private certificate authority, check another name, or present a client certificate; see [Reading and Storing Mail](../read.md) and the security page.
`name`                  | `name`                | `localhost`    | The client's own host name, sent with EHLO.
`auth`                  | `auth`                | none           | An authenticator, or settings such as `['type' => 'login', 'username' => ..., 'password' => ...]`. See [SMTP authentication](smtp-authentication.md).
`allow_insecure_auth`   | `allowInsecureAuth`   | `false`        | Allow `auth` with `security` set to `none`.
`connection_time_limit` | `connectionTimeLimit` | none           | Seconds after which the transport opens a new connection rather than reusing it; QUIT is then not sent.
`use_complete_quit`     | `useCompleteQuit`     | `true`         | Send QUIT before closing the connection.
`logger`                | `logger`              | none           | A PSR-3 logger for the session, at debug level, credentials redacted. See [logging the session](smtp-authentication.md#logging-the-session).

The laminas-mail `ssl` setting is read too, as IMAP and POP3 read it: `ssl`
means `tls` (TLS from the start), `tls` means `starttls`, and `false` or `none`
means a plain connection. It is deprecated: use `security`, and don't give
both.

When TLS from the start fails on port 25, 110, 143 or 587, where servers expect
STARTTLS, the error says so and suggests `security: 'starttls'`.

The connection settings are also available on their own as
`SmtpConfig::$connection`, a `Contenir\Mail\Protocol\ConnectionConfig`.
`SmtpConfig::DEFAULT_SECURITY` holds the default security.

## What the session checks

- **TLS.** With `starttls` the transport asks for STARTTLS after EHLO and fails if
  the server does not list it, refuses it, or sends anything after agreeing to it
  (text sent then could have been injected by an attacker). TLS 1.2 or later is
  required, the certificate is verified, and EHLO is sent again over TLS so that
  nothing learned before encryption is trusted.
- **Credentials** are only sent over TLS, unless `allow_insecure_auth` is set, and
  only with a mechanism the server lists. Lines carrying credentials appear in
  `getLog()` and `getRequest()` as `[credentials hidden]`.
- **Commands.** Every command argument (envelope addresses, the EHLO name, VRFY)
  is refused if it contains CR, LF or NUL; envelope addresses also may not contain
  `<`, `>` or spaces outside a quoted local part.
- **Message text.** Bare CR and LF become CRLF and a line starting with `.` is
  doubled, so the text can never end the DATA section early (SMTP smuggling). The
  message is not otherwise changed: a line longer than 998 bytes throws, since
  folding it would alter the content and break DKIM signatures. Text and HTML
  parts are quoted-printable, which keeps their lines short.
- **Extensions.** The EHLO reply is read into capabilities. The message size is
  declared with `SIZE`, and a message larger than the server's limit is refused
  before it is sent. `BODY=8BITMIME` is declared for 8-bit content when the
  server supports it, and `SMTPUTF8` for addresses that are not ASCII, which
  throw if the server does not support it.
- **Replies** are limited to 100 lines, and a malformed reply line throws.

## Methods

```php
__construct(SmtpConfig|iterable|null $config = null, ClockInterface $clock = new SystemClock())
getConfig(): SmtpConfig
send(Message $message): void
setEnvelope(?Envelope $envelope): void
getEnvelope(): ?Envelope
setConnection(Protocol\Smtp $connection): void
getConnection(): ?Protocol\Smtp
disconnect(): void
setAutoDisconnect(bool $flag): void
getAutoDisconnect(): bool
```

### Envelope

The envelope sender and recipients come from the message: its Sender, or else its
first From address, and its To, Cc and Bcc addresses. An `Envelope` replaces
either:

```php
use Contenir\Mail\Transport\Envelope;

$transport->setEnvelope(new Envelope(from: 'bounces@example.com', to: ['archive@example.com']));
```

Envelope addresses are validated when the envelope is made.

## The protocol

`Contenir\Mail\Protocol\Smtp` is the session underneath. It takes a
`ConnectionConfig`, with `use_complete_quit` and `allow_insecure_auth` in its
`$config` array:

```php
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Smtp;

$smtp = new Smtp(new ConnectionConfig('smtp.example.com'), authenticator: $login);
$smtp = new Smtp('smtp.example.com', 587, ['ssl' => 'tls']);   // laminas-mail form, deprecated
```

The laminas-mail forms, a host name or a settings array in place of the
`ConnectionConfig`, are deprecated. In them `ssl` keeps its old meaning:
`'ssl'` is TLS from the start, `'tls'` is STARTTLS, and `'none'`, `''` or
`false` is a plain connection. Leaving `ssl` out now means STARTTLS.
`novalidatecert` turns certificate verification off, as does the deprecated
`setNoValidateCert(true)`; set `verifyPeer` in the `ConnectionConfig` instead.
