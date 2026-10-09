# contenir/contenir-mail

[![Continuous Integration](https://github.com/contenir/contenir-mail/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/contenir/contenir-mail/actions/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/contenir/contenir-mail/graph/badge.svg)](https://codecov.io/gh/contenir/contenir-mail)

Compose, parse, store and send text and MIME-compliant multipart e-mail messages.

`contenir/contenir-mail` is a maintained continuation of the abandoned
[laminas/laminas-mail](https://github.com/laminas/laminas-mail) and
[laminas/laminas-mime](https://github.com/laminas/laminas-mime) components, which
were themselves the successors of Zend Framework's `Zend\Mail` and `Zend\Mime`.
The complete history of both repositories, back to 2009, is preserved here, so
every contributor keeps their authorship in `git log` and `git blame`.

- **Messages:** `Message`, `Headers` and the `Header\*` classes, `Address` and `AddressList`.
- **MIME:** `Mime\Part`, `Mime\Multipart`, `Mime\Attachment`, `Mime\Mime` and `Mime\Decode`, formerly laminas-mime.
- **Transports:** `Smtp`, `Sendmail`, `File` and `InMemory`, each configured with a typed `*Config`,
  and `Failover` to try several in turn.
- **Protocols:** SMTP, IMAP and POP3 clients over a small connection layer, with a scripted
  `Testing\InMemoryConnection` for testing code that sends or reads mail.
- **Storage:** read and write `Mbox` and `Maildir`, and read over `Imap` and `Pop3`.
- **Container support:** optional PSR-11 factories and a `ConfigProvider`, for Mezzio, laminas-mvc
  or any PSR-11 container. No container is required: `psr/container` is suggested, not required.

## Requirements

- PHP 8.3, 8.4 or 8.5
- `ext-iconv`
- `ext-openssl` for TLS connections, and `ext-fileinfo` to detect attachment types

ext-mbstring is not needed. The only other dependencies are the PSR clock and
container interfaces and the Symfony IDN polyfill; install ext-intl for faster
and stricter handling of internationalised domain names.

## Install

```bash
composer require contenir/contenir-mail
```

## Usage

```php
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Transport\Sendmail;

$message = new Message();
$message->addFrom('sender@example.org', 'Sender');
$message->addTo('recipient@example.com', 'Recipient');
$message->setSubject('Hello');
$message->setText('This is the text of the e-mail.');
$message->setHtml('<p>This is the text of the e-mail.</p>');
$message->attach(Attachment::fromPath('/path/to/report.pdf'));

(new Sendmail())->send($message);
```

Sending through SMTP with STARTTLS, which is the default:

```php
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Transport\Smtp;
use Contenir\Mail\Transport\SmtpConfig;

$transport = new Smtp(new SmtpConfig(
    host: 'smtp.example.com',
    auth: new Login('orders', $password),
));

// or from configuration
$transport = new Smtp([
    'host' => 'smtp.example.com',
    'auth' => ['type' => 'login', 'username' => 'orders', 'password' => $password],
]);

$transport->send($message);
```

Reading a mailbox:

```php
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\Maildir;

foreach (new Maildir(['dirname' => '/var/mail/jo']) as $number => $message) {
    if (! $message->hasFlag(Flag::Seen)) {
        echo $message->getSubject(), ' from ', $message->getFrom()->first()?->getEmail(), "\n";
    }
}
```

`getTextBody()` and `getHtmlBody()` return a message's bodies as UTF-8. The
HTML is returned as sent, so sanitise it before showing it.

See the [documentation](docs/book/index.md) for transports, attachments,
character sets and reading mail.

## Security

Mail libraries sit on trust boundaries: application input becomes protocol
commands and shell arguments, and hostile servers and messages are parsed. This
package checks every input where it enters, and each protection below has a
regression test.

- **Injection.** Header values, display names, MIME parameters, SMTP, IMAP and
  POP3 command arguments and sendmail arguments refuse CR, LF, NUL and other
  characters that could end or extend them. IMAP strings that need it are sent as
  literals. SMTP bodies have their line endings normalised before dot-stuffing,
  which closes SMTP smuggling.
- **Sendmail.** `-f` is only ever passed for a shell-safe sender (the 2016
  PHPMailer and Zend Mail CVEs), and sendmail can be run without a shell at all.
- **TLS by default.** Connections require STARTTLS unless told otherwise, verify
  the server certificate, accept TLS 1.2 or later only, refuse to continue in plain
  text when STARTTLS fails, and discard anything a server sends before the
  handshake. SMTP AUTH is refused over an unencrypted connection.
- **Secrets.** Passwords and tokens are kept out of protocol logs, exception traces
  and `var_dump()` output.
- **Hostile input.** Server responses, header blocks, MIME nesting and part counts
  have limits. Storage paths must be local files, symlinks are refused, and Maildir
  files are created exclusively with mode 0600. Protocol and storage objects refuse
  to be unserialized.
- **Spoofing.** Addresses refuse control characters and bidirectional overrides,
  and internationalised domains are checked with the IDNA2008 bidi and CONTEXTJ
  rules. Attachment filenames read from mail have a sanitised accessor.

The [security documentation](docs/book/security.md) maps every protection to the
test that proves it and to the published vulnerabilities it guards against, and
lists open findings. [Standards](docs/book/standards.md) covers RFC conformance.
Report vulnerabilities as described in [SECURITY.md](SECURITY.md).

## Coming from laminas-mail and laminas-mime

contenir-mail keeps the Zend_Mail and laminas-mail vocabulary: `Message`,
`Headers`, `Address`, MIME parts, transports, protocols and storage keep their
names and their roles. The API underneath is modernised for PHP 8.3, so it is not
a drop-in replacement: values are typed and immutable, and a few classes have
gone.

```bash
composer remove laminas/laminas-mail laminas/laminas-mime
composer require contenir/contenir-mail
```

Then rewrite the `Laminas\Mime` and `Laminas\Mail` namespaces and update the code
that touches the changed APIs. The [migration guide](docs/book/migrating.md) has
the steps and the full mapping tables. It starts with the changes your code will
not complain about, so check these first:

- `Storage\Message::getFlags()` is a list, so `isset($flags['\Seen'])` is always
  false; use `hasFlag(Flag::Seen)`.
- `Storage\Part::getContent()` returns the decoded body; your own
  `base64_decode()` corrupts attachments.
- IMAP folder names are given and returned as UTF-8; encoding them to modified
  UTF-7 yourself sends them to the wrong folder.
- `catch` blocks naming the removed `Storage\Part\Exception` classes never match.
- Headers, addresses and MIME parts are immutable; a `with()` whose result you
  discard does nothing.
- `security: 'tls'` is TLS from the start, not STARTTLS as laminas-mail's
  `ssl: 'tls'` was, and STARTTLS is now required by default.
- `Mime\Part` defaults to base64 rather than 8bit.
- `Crammd5` and `Xoauth2` are now `CramMd5` and `XOAuth2`, which only fails on a
  case-sensitive file system.

## Development

The QA toolchain comes from
[contenir/contenir-qa-tools](https://github.com/contenir/contenir-qa-tools):
Mago for formatting, linting and static analysis, PHPUnit 11, and Infection for
mutation testing.

```bash
composer check            # cs-check, static-analysis and test
composer cs-fix           # mago format + mago lint --fix
composer mutation-test    # Infection; needs a coverage driver such as pcov or Xdebug
```

CI fails if any mutant of covered code survives. Infection does not mutate code the
tests never reach.

Findings inherited from laminas-mail and laminas-mime are recorded in
`mago-lint-baseline.toml` and `mago-analyze-baseline.toml`. Many can only be fixed
by breaking the public API. New code is held to the full standard.

Tests that need a live IMAP, POP3 or SMTP server are skipped unless enabled through
the `TESTS_CONTENIR_MAIL_*` variables documented in `phpunit.xml.dist`.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md) and [COPYRIGHT.md](COPYRIGHT.md).
