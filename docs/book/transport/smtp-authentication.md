# SMTP Authentication

An SMTP transport logs in with an authenticator, given as the `auth` setting.
contenir-mail ships four, in `Contenir\Mail\Protocol\Smtp\Auth`:

Class      | `type`     | Mechanism | Settings
---------- | ---------- | --------- | --------
`Plain`    | `plain`    | PLAIN     | `username`, `password`
`Login`    | `login`    | LOGIN     | `username`, `password`
`CramMd5`  | `cram-md5` | CRAM-MD5  | `username`, `password`
`XOAuth2`  | `xoauth2`  | XOAUTH2   | `username`, `access_token`

```php
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
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
    auth: new XOAuth2('jo@example.com', $accessToken),
));
```

The session authenticates after EHLO and STARTTLS, and only:

- over TLS: with `security` set to `none`, configuring `auth` throws unless
  `allow_insecure_auth` is set;
- with a mechanism the server lists in its EHLO reply.

PLAIN and LOGIN send the password itself, protected only by TLS. CRAM-MD5 sends
an HMAC-MD5 of a challenge instead, but MD5 is weak and the server must keep the
password in a recoverable form; prefer PLAIN or LOGIN over TLS where both are
offered.

## Keeping credentials secret

- Passwords and tokens are `#[SensitiveParameter]`, so they do not appear in
  stack traces.
- `var_dump()` and `print_r()` of an authenticator, or of an `SmtpConfig` holding
  one, show the username and `[hidden]`.
- Lines that carry credentials are logged as `[credentials hidden]` by
  `Protocol\Smtp::getLog()` and returned as such by `getRequest()`.
- Usernames may not contain control characters; a PLAIN password may not contain
  NUL, and an XOAUTH2 token may not contain control characters, since these
  separate the fields of the response.

## Writing an authenticator

Implement `AuthenticatorInterface`. The session hands `authenticate()` a channel
for the exchange; use `exchangeSecret()` for every line that carries credentials.

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
