# SMTP Authentication

An SMTP transport logs in with an authenticator, given as the `auth` setting.
contenir-mail ships five. `Plain`, `Login` and `CramMd5` are in
`Contenir\Mail\Protocol\Smtp\Auth`. `ScramSha256` and `Xoauth2` are SASL
mechanisms in `Contenir\Mail\Protocol\Sasl`, which sign in to IMAP and POP3
too.

Class                | `type`          | Mechanism     | Settings
-------------------- | --------------- | ------------- | --------
`Smtp\Auth\Plain`    | `plain`         | PLAIN         | `username`, `password`
`Smtp\Auth\Login`    | `login`         | LOGIN         | `username`, `password`
`Smtp\Auth\CramMd5`  | `cram-md5`      | CRAM-MD5      | `username`, `password`
`Sasl\ScramSha256`   | `scram-sha-256` | SCRAM-SHA-256 | `username`, `password`
`Sasl\Xoauth2`       | `xoauth2`       | XOAUTH2       | `username`, `access_token`

The `type` is the mechanism's name as IANA registers it, in any case, so
`CRAM-MD5` works too. The spellings without the hyphens, such as `crammd5` and
`scramsha256`, or with underscores in their place, still work but are
deprecated, and raise an `E_USER_DEPRECATED` notice. `type` is required here;
the mailbox `auth` setting, which only takes `xoauth2` and `scram-sha-256`,
defaults it to `xoauth2`, as it did before SCRAM was added.

```php
use Contenir\Mail\Protocol\Sasl\Xoauth2;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Transport\Smtp;
use Contenir\Mail\Transport\SmtpConfig;

$transport = new Smtp(new SmtpConfig(host: 'smtp.example.com', port: 587, auth: new Login('orders', $password)));

$transport = new Smtp([
    'host' => 'smtp.example.com',
    'port' => 587,
    'auth' => ['type' => 'login', 'username' => 'orders', 'password' => $password],
]);

// Google or Microsoft 365, with an OAuth 2.0 access token
$transport = new Smtp(new SmtpConfig(
    host: 'smtp.gmail.com',
    port: 587,
    auth: new Xoauth2('jo@example.com', $accessToken),
));
```

Access tokens expire. For a long-running worker, pass a Closure instead of the
token, or a Closure or invokable object under `access_token`. It is called for a fresh token at
each AUTH:

```php
new Xoauth2('jo@example.com', static fn(): string => $tokens->fresh());
```

The session authenticates after EHLO and STARTTLS, and only:

- over TLS: with `security` set to `none`, configuring `auth` throws unless
  `allow_insecure_auth` is set;
- with a mechanism the server lists in its EHLO reply.

PLAIN and LOGIN send the password itself, protected only by TLS. CRAM-MD5 sends
an HMAC-MD5 of a challenge instead, but MD5 is weak and the server must keep the
password in a recoverable form; prefer PLAIN or LOGIN over TLS where both are
offered.

You choose the authenticator, not the server: a server that does not offer the
configured mechanism is refused, never downgraded to another one.

## SCRAM-SHA-256

SCRAM-SHA-256 (RFC 5802, RFC 7677) proves the password without sending it, and
the server proves in return that it knows the password too. Where the server
offers it, prefer it to PLAIN and LOGIN.

```php
use Contenir\Mail\Protocol\Sasl\ScramSha256;

$transport = new Smtp(new SmtpConfig(
    host: 'smtp.example.com',
    port: 587,
    auth: new ScramSha256('orders', $password),
));

$transport = new Smtp([
    'host' => 'smtp.example.com',
    'auth' => ['type' => 'scram-sha-256', 'username' => 'orders', 'password' => $password],
]);
```

- The exchange fails closed. If the server's final message does not carry the
  signature only the password's holder could compute, or the server reports
  success without sending one, the exchange throws. A server message the client
  refuses is answered with `*`, which cancels the exchange.
- The server's iteration count must be between 4096 and 1,000,000, so a hostile
  server cannot make the client spin. Its nonce must extend the client's, which
  comes from `random_bytes()`.
- Channel binding (`SCRAM-SHA-256-PLUS`) is not supported. It needs the
  `tls-unique` or `tls-exporter` value of the TLS session, which PHP does not
  expose. The client sends the gs2 header `n,,`, which says it does not support
  binding. The exchange therefore does not prove which TLS connection it ran
  over, so keep TLS on and the certificate verified.
- Usernames and passwords in printable ASCII are sent as they are. Other text is
  normalised to Unicode NFKC, the core of SASLprep (RFC 4013), with the intl
  extension's `Normalizer`. Without intl, such credentials are refused rather
  than sent unprepared. The rest of SASLprep, such as its tables of prohibited
  and bidirectional characters, is not applied.
- SCRAM-SHA-1 is not offered.

## Keeping credentials secret

- Passwords and tokens are `#[SensitiveParameter]`, so they do not appear in
  stack traces.
- `var_dump()` and `print_r()` of an authenticator, or of an `SmtpConfig` holding
  one, show the username and `[hidden]`.
- Lines that carry credentials are logged as `[credentials hidden]` by
  `Protocol\Smtp::getLog()` and returned as such by `getRequest()`.
- A PSR-3 `logger` (see [logging the session](#logging-the-session)) gets
  every response of the exchange as `[redacted]`.
- Usernames may not contain control characters; a PLAIN password may not contain
  NUL, and an XOAUTH2 token may not contain control characters, since these
  separate the fields of the response. In a SCRAM username, `=` and `,` are
  escaped as `=3D` and `=2C`.

## Writing a mechanism

A mechanism written once signs in to SMTP, IMAP and POP3. Implement
`Protocol\Sasl\MechanismInterface`, whose `start()` returns a
`Protocol\Sasl\ExchangeInterface` for one sign-in, and wrap it in a
`Smtp\Auth\SaslAuthenticator` for SMTP:

```php
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Sasl\ExchangeInterface;
use Contenir\Mail\Protocol\Sasl\MechanismInterface;
use Contenir\Mail\Protocol\Smtp\Auth\SaslAuthenticator;

final readonly class Anonymous implements MechanismInterface, ExchangeInterface
{
    public function mechanism(): string
    {
        return 'ANONYMOUS';
    }

    public function start(): ExchangeInterface
    {
        return $this; // a mechanism with state per sign-in returns a new object
    }

    public function initialResponse(): ?string
    {
        return base64_encode('trace@example.com');
    }

    public function respond(string $challenge): string
    {
        throw new RuntimeException('ANONYMOUS takes no challenge');
    }

    public function complete(): void {}

    public function refusal(string $reason): string
    {
        return '' === $reason ? 'The server refused anonymous access' : $reason;
    }
}

new SmtpConfig(host: 'smtp.example.com', auth: new SaslAuthenticator(new Anonymous()));
$imap->authenticate(new Anonymous());
```

- Challenges and responses are base64, as the protocols carry them. An
  initial response of `""` is empty, sent as `=` where the protocol needs one;
  `null` means none, and the server's first challenge goes to `respond()`.
- SMTP and POP3 send the initial response after the server's first challenge,
  IMAP with the command when the server offers SASL-IR (RFC 4959).
- A `RuntimeException` from `respond()` cancels the exchange with `*` before
  it is thrown. `complete()` runs after the server accepts, so a mechanism
  that checks the server, as SCRAM does, can refuse an unproven acceptance.
- `refusal()` makes the message of the exception thrown when the server
  refuses, from the reason it gave.
- Every response is sent as a secret: never logged, never in `getRequest()`.

## Writing an authenticator

For SMTP alone, implement `AuthenticatorInterface`. The session hands
`authenticate()` a channel for the exchange; use `exchangeSecret()` for every
line that carries credentials.

```php
use Contenir\Mail\Protocol\Smtp\Auth\AuthenticatorInterface;
use Contenir\Mail\Protocol\Smtp\Auth\ChannelInterface;

final readonly class Anonymous implements AuthenticatorInterface
{
    public function mechanism(): string
    {
        return 'ANONYMOUS';
    }

    public function authenticate(ChannelInterface $channel): void
    {
        $channel->exchange('AUTH ANONYMOUS', 334);
        $channel->exchangeSecret(base64_encode('trace@example.com'), 235);
    }
}
```

`exchange()` and `exchangeSecret()` send one line, refuse a line containing CR,
LF or NUL, and throw `Protocol\Exception\RuntimeException` when the server replies
with a code other than the expected one. They return the text of the reply, which
for a 334 reply is the base64 challenge.

## Logging the session

Give a PSR-3 logger as `logger`, and the session is logged at debug level,
with `C:` before each line the client sends and `S:` before each the server
sends. It needs `psr/log` (1.1, 2 or 3), which contenir-mail only suggests.

```php
$transport = new Smtp(new SmtpConfig(host: 'smtp.example.com', auth: $auth, logger: $logger));

$transport = new Smtp(['host' => 'smtp.example.com', 'logger' => $logger]);
```

Credentials are never logged. Every SASL response, password, token and digest
is sent as a secret and logged as `[redacted]`, or as the command it starts,
such as `AUTH PLAIN [redacted]`. Any other line that starts LOGIN,
AUTHENTICATE, AUTH, USER, PASS or APOP has its arguments redacted too. The
rest is logged as it is, message contents included, so keep the log as safe
as the mail.

The logger wraps the connection in a `Protocol\LoggingConnection`, which can
also wrap one directly:
`new LoggingConnection(new StreamConnection(), $logger)`.
A connection of your own that records what it sends should implement
`Protocol\RedactingConnectionInterface`, whose `writeSecret()` the protocols
use for credentials.

## Servers that close idle connections

Some servers close a connection after a while. Set `connection_time_limit` to the
number of seconds after which the transport should open a new connection rather
than reuse the old one; QUIT is then not sent, since the server may have gone.

```php
$transport = new Smtp([
    'host'                  => 'smtp.example.com',
    'auth'                  => ['type' => 'plain', 'username' => 'orders', 'password' => $password],
    'connection_time_limit' => 300,
]);
```
