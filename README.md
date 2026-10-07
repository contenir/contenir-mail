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
- **MIME:** `Mime\Message`, `Mime\Part`, `Mime\Mime` and `Mime\Decode`, formerly laminas-mime.
- **Transports:** `Smtp`, `Sendmail`, `File` and `InMemory`.
- **Storage:** read and write `Mbox` and `Maildir`, and read over `Imap` and `Pop3`.

## Requirements

- PHP 8.3, 8.4 or 8.5
- `ext-iconv`
- `laminas/laminas-servicemanager` 3.24 or later when sending through SMTP

## Install

```bash
composer require contenir/contenir-mail
```

## Usage

```php
use Contenir\Mail\Message;
use Contenir\Mail\Transport\Sendmail;

$message = new Message();
$message->setEncoding('UTF-8');
$message->addFrom('sender@example.org', 'Sender');
$message->addTo('recipient@example.com', 'Recipient');
$message->setSubject('Hello');
$message->setBody('This is the text of the e-mail.');

(new Sendmail())->send($message);
```

See the [documentation](docs/book/index.md) for transports, attachments,
character sets and reading mail.

## Migrating from laminas-mail and laminas-mime

The public API is kept as close to laminas-mail 2.25 and laminas-mime 2.12 as
possible: classes, methods and options keep their names and signatures, and only
the namespace changes.

| laminas                                         | contenir-mail            |
| ----------------------------------------------- | ------------------------ |
| `Laminas\Mail\*`                                | `Contenir\Mail\*`        |
| `Laminas\Mime\*`                                | `Contenir\Mail\Mime\*`   |
| `laminas/laminas-mail` + `laminas/laminas-mime` | `contenir/contenir-mail` |

1. Replace both packages:

   ```bash
   composer remove laminas/laminas-mail laminas/laminas-mime
   composer require contenir/contenir-mail
   ```

2. Rewrite the namespaces. `Laminas\Mime` must be rewritten before `Laminas\Mail`:

   ```bash
   grep -rlE 'Laminas\\+(Mail|Mime)' src config test \
     | xargs perl -pi -e 's/Laminas(\\+)Mime/Contenir${1}Mail${1}Mime/g; s/Laminas(\\+)Mail/Contenir${1}Mail/g'
   ```

3. Register `Contenir\Mail\ConfigProvider` (Mezzio) or the `Contenir\Mail` module
   (laminas-mvc) in place of the Laminas ones.

There are no `Laminas\*` class aliases. The following were also removed:

- The legacy `Zend\Mail\*` service names, and the normalised `zendmail*` and
  `laminasmail*` aliases of `SmtpPluginManager`. Use the class names or the short
  names (`smtp`, `login`, `plain`, `crammd5`, `xoauth2`).
- The `TESTS_LAMINAS_MAIL_*` test environment variables, now `TESTS_CONTENIR_MAIL_*`.
- `Headers::setPluginClassLoader()`, `Headers::getPluginClassLoader()` and
  `Header\HeaderLoader`, deprecated since laminas-mail 2.12, together with the
  abandoned `laminas/laminas-loader` dependency. Use `Headers::setHeaderLocator()`
  and `Headers::getHeaderLocator()` with a `Header\HeaderLocatorInterface`.

Behaviour changes:

- `HeaderWrap::mimeDecodeValue()` no longer uses ext-imap, which left PHP core in
  8.4. A built-in RFC 2047 decoder handles multibyte characters split across
  encoded words on every PHP version.
- A failed `AbstractProtocol::_connect()` no longer leaves its temporary error
  handler installed.

## Development

The QA toolchain comes from
[contenir/contenir-qa-tools](https://github.com/contenir/contenir-qa-tools):
Mago for formatting, linting and static analysis, and PHPUnit 11.

```bash
composer check            # cs-check, static-analysis and test
composer cs-fix           # mago format + mago lint --fix
```

Findings inherited from laminas-mail and laminas-mime are recorded in
`mago-lint-baseline.toml` and `mago-analyze-baseline.toml`. Many can only be fixed
by breaking the public API. New code is held to the full standard.

Tests that need a live IMAP, POP3 or SMTP server are skipped unless enabled through
the `TESTS_CONTENIR_MAIL_*` variables documented in `phpunit.xml.dist`.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md) and [COPYRIGHT.md](COPYRIGHT.md).
