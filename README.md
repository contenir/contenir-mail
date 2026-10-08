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
- **Transports:** `Smtp`, `Sendmail`, `File` and `InMemory`, each configured with a typed `*Config`.
- **Protocols:** SMTP, IMAP and POP3 clients over a small connection layer, with a scripted
  `Testing\InMemoryConnection` for testing code that sends or reads mail.
- **Storage:** read and write `Mbox` and `Maildir`, and read over `Imap` and `Pop3`.
- **Container support:** optional PSR-11 factories and a `ConfigProvider`, for Mezzio, laminas-mvc
  or any PSR-11 container. No container is required.

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
   (laminas-mvc) in place of the Laminas ones. Neither is needed without a container.

4. Update code that touches the APIs below.

### Addresses and headers

Addresses, address lists, every header class and the `Headers` collection are
immutable values. `Message` is still built step by step with its setters, but
each setter swaps in a new value rather than changing a shared one, so a cloned
message never affects the original.

| laminas-mail | contenir-mail |
| --- | --- |
| `$message->getTo()->add($email, $name)` | `$message->addTo($email, $name)` |
| `$message->getHeaders()->addHeaderLine('X-Id', '1')` | `$message->addHeader(new GenericHeader('X-Id', '1'))` |
| `$message->getHeaders()->removeHeader('X-Id')` | `$message->removeHeader('X-Id')` |
| `$headers->get('Received')` returning a header, an `ArrayIterator` or `false` | `$headers->get('Received')` (first or `null`) and `$headers->all('Received')` (list) |
| `$headers->addHeader($h)` / `removeHeader($name)` | `$headers->with($h)` (replace), `withAdded($h)` (append), `without($name)` |
| `$header->setEncoding('UTF-8')`, `$message->setEncoding('UTF-8')` | Headers encode themselves: plain ASCII stays readable, anything else is RFC 2047 encoded as UTF-8. A body's character set is given with its text: `setText($text, 'ISO-8859-1')` |
| `$header->getFieldValue(HeaderInterface::FORMAT_ENCODED)` | `$header->getEncodedFieldValue()` |
| `new Subject(); $subject->setSubject('Hi')` and other setters | Constructors: `new Subject('Hi')`, `new ContentType('text/plain', ['charset' => 'UTF-8'])`, `new Date(new DateTimeImmutable())` |
| `$contentType->addParameter('charset', 'UTF-8')` | `$contentType->withParameter('charset', 'UTF-8')` |
| `new ContentTransferEncoding(); ->setTransferEncoding('base64')` | `new ContentTransferEncoding(TransferEncoding::Base64)` |
| `$messageId->setId()` (generated) | `MessageId::generate()` |
| `$references->setIds([...])` | `new References(...$ids)` |
| `AddressList::add()`, `addMany()`, `merge()`, `delete()` | `with()`, `fromIterable()`, `withList()`, `without()` |
| `Address\AddressInterface` | `Address` (a `final readonly` value) |
| `Header\HeaderLocator::add()` / `remove()` | `new HeaderLocator(['x-name' => MyHeader::class])` or `->with()`, passed to `Headers::fromString()` |
| `GenericMultiHeader`, `MultipleHeadersInterface`, `StructuredInterface`, `UnstructuredInterface` | Removed; `Headers` keeps repeated headers such as `Received` as separate entries |
| `IdentificationField` | `AbstractIdentificationField` |

### MIME bodies

`Message` builds the MIME structure itself, choosing `multipart/alternative`,
`related` and `mixed` as the parts require and adding `MIME-Version` and
`Content-Type` with the boundary. MIME parts are immutable values.

| laminas-mail and laminas-mime | contenir-mail |
| --- | --- |
| `new Mime\Message()`, `setParts()`, `$message->setBody($mimeMessage)` plus a hand-set `Content-Type` | `$message->setText()`, `setHtml()`, `attach()`, `embed()` |
| A `Mime\Message` rendered into a `Mime\Part` to nest `multipart/alternative` | `new Multipart(MultipartType::Alternative, [...])`, nested directly |
| `$part = new Part($c); $part->type = ...; $part->filename = ...` | `new Part($c, type: ..., filename: ...)`, `Part::text()`, `Part::html()` |
| `Part` for an attachment, with disposition and encoding set by hand | `Attachment::fromPath()`, `fromString()`, `inline()` |
| `Mime::DISPOSITION_*`, `Mime::ENCODING_*` strings on a part | `Disposition` and `TransferEncoding` enums |
| `new Mime($boundary)`, `Mime\Message::setMime()` | `new Multipart($type, $parts, boundary: $boundary)` |
| `Mime::boundary()`, `boundaryLine()`, `mimeEnd()`, `Mime\Message::generateMessage()` | `PartWriter::body($part)` |
| `Mime\Message::createFromMessage()` | `Mime\Decode`, or a stored message, whose parts implement the same `PartInterface` |
| `Part::isStream()`, `getEncodedStream()` | A stream given as content is read and encoded in chunks when the message is written |
| `Message::getEncoding()` | Removed |
| `Date` header from the current time | From an injected PSR-20 clock: `new Message(clock: $clock)` |

### Settings: Config objects

Every transport, protocol and storage keeps its settings in a typed, immutable
`*Config` object. Constructors still accept the familiar laminas arrays and read
them into the Config, so `new Imap(['host' => ..., 'user' => ...])` keeps working,
but unknown keys and values of the wrong type now throw, naming the key. Keys may
be snake_case or camelCase, and strings from environment variables such as
`'587'` or `'false'` are accepted.

### Transports and SMTP

| laminas-mail | contenir-mail |
| --- | --- |
| `Transport\SmtpOptions` | `Transport\SmtpConfig`, or an array given to `new Smtp([...])` |
| `connection_class` with `connection_config` `username` and `password` | `auth`: an authenticator, or `['type' => 'login', 'username' => ..., 'password' => ...]` |
| `connection_config['ssl']` = `'ssl'` / `'tls'` | `security` = `'tls'` / `'starttls'` (the default) / `'none'` |
| `connection_config['novalidatecert']` | `verify_peer` |
| `Protocol\Smtp\Auth\Plain`, `Login`, `Crammd5`, `Xoauth2` (subclasses of `Protocol\Smtp`) | `Protocol\Smtp\Auth\Plain`, `Login`, `CramMd5`, `XOAuth2`, implementing `AuthenticatorInterface` |
| `Protocol\SmtpPluginManager`, `Transport\Smtp::setPluginManager()` / `plugin()` | Removed; implement `AuthenticatorInterface` for another mechanism |
| `Transport\FileOptions` | `Transport\FileConfig` |
| `new Sendmail($parameters)`, `setParameters()`, `setCallable()` | `new Sendmail($parameters)` or a `SendmailConfig`, with `mailer:` for a custom callable |
| `Transport\Factory::create($spec)` | `Container\TransportFactory`, reading `config['mail']['transport']` with a `type` key |
| `MessageFactory::getInstance($options)` | Removed; use the `Message` setters |
| `ConfigProvider::getDependencyConfig()` | `ConfigProvider::getDependencies()` |
| `Transport\Envelope` (options object) | `new Envelope(from: ..., to: ...)`, readonly and validated |

### IMAP and POP3 protocols

| laminas-mail | contenir-mail |
| --- | --- |
| `new Protocol\Imap($host, $port, $ssl)`, the same for `Pop3` | The same, or a `ConnectionConfig`; an omitted `$ssl` now means STARTTLS |
| `$ssl = true` or an unknown string | Throws; use `'ssl'`, `'tls'`, `'none'` or `false` |
| Socket handling inside the protocol classes | `ConnectionInterface`, with `StreamConnection` and `Testing\InMemoryConnection` |
| laminas-stdlib `ErrorHandler` | Internal; no laminas-stdlib dependency |
| Commands returning raw response lines | `login()`, `select()`, `store()` and the other commands are typed; boolean commands return `bool` |
| Unlimited response sizes | `ResponseLimits` (8 MiB lines, 64 MiB responses by default), set with `setResponseLimits()` |

### Storage

| laminas-mail | contenir-mail |
| --- | --- |
| `$params` arrays read by `ParamsNormalizer` | `MboxConfig`, `MaildirConfig`, `ImapConfig`, `Pop3Config`; arrays still work |
| `Storage::FLAG_SEEN` and the other constants | The `Storage\Flag` enum; IMAP flag strings are accepted where flags are given |
| `$message->subject` and other magic header properties | `getSubject()`, `getFrom()`, `getDate()`, `getHeaders()` |
| `$storage[3]`, `unset($storage[3])` | `getMessage(3)`, `removeMessage(3)` |
| `$folders->INBOX->Archive` | `getFolder('INBOX')`, `getFolders()` |
| `getSize()` and `getUniqueId()` without a number, returning every message | `getSizes()` and `getUniqueIds()` |
| `hasTop`, `hasFlags` and the other `has*` properties | `getCapabilities()` |
| `Part::getContent()` returning the transferred text | `getContent()` returns it decoded; `getEncodedContent()` returns it as transferred |
| `messageEOL`, `getTopLines()`, `Storage::FLAG_UNSEEN` | Removed |

Reading mail is more forgiving: a header that its class cannot parse, such as a
malformed `Date`, is kept as a `GenericHeader` rather than making the whole
message unreadable.

### Removed

- The legacy `Zend\Mail\*` service names, and `SmtpPluginManager` with all its
  aliases.
- The `laminas/laminas-stdlib`, `laminas/laminas-servicemanager` and
  `webmozart/assert` dependencies.
- The `TESTS_LAMINAS_MAIL_*` test environment variables, now `TESTS_CONTENIR_MAIL_*`.
- `Headers::setPluginClassLoader()`, `Headers::getPluginClassLoader()` and
  `Header\HeaderLoader`, deprecated since laminas-mail 2.12, together with the
  abandoned `laminas/laminas-loader` dependency.
- The `laminas/laminas-validator` dependency. Addresses and host names are checked
  by internal validators that accept and reject what laminas-validator 2 did, so
  an application is free to use either laminas-validator major version.
  `AbstractProtocol::$validHost` is now a
  `Contenir\Mail\Validator\HostnameValidator` rather than a `ValidatorChain`.

### Behaviour changes

- `HeaderWrap::mimeDecodeValue()` no longer uses ext-imap, which left PHP core in
  8.4. A built-in RFC 2047 decoder handles multibyte characters split across
  encoded words, words in any charset case, and tab-folded lines.
- Connections to mail servers require STARTTLS by default. Pass
  `security: Security::None` (or `'security' => 'none'`) for a local relay without
  TLS.
- SMTP refuses to authenticate over an unencrypted connection unless
  `allow_insecure_auth` is set, and only uses mechanisms the server advertises.
- SMTP refuses a body line longer than 998 octets instead of folding it, which
  changed the content and broke DKIM signatures.
- Text parts write their line breaks as quoted-printable hard line breaks and keep
  trailing whitespace, and `multipart/related` names its root type.
- Headers read from stored mail write back the exact text they were read with
  until they are changed, so forwarded and DKIM-signed headers survive.
- Generated Message-IDs use a reserved domain rather than the machine's host name.
- Without a port, SMTP connects to 587, the submission port, when using STARTTLS
  (the default); 465 for TLS and 25 for a plain connection.
- `Sendmail` can run the sendmail program directly, with no shell, when given a
  `path`; without one it uses `mail()` as before.
- The container's transport configuration must name its `type`; it no longer
  defaults to sendmail.
- `XOAuth2` accepts a Closure that returns a fresh access token at each AUTH.
- A header word too long to fold within 998 characters is written as encoded
  words, so no header line ever exceeds the RFC 5322 limit.
- Encoded words always hold whole characters (RFC 2047, section 5). A header name
  may be up to 997 characters; when nothing of the value fits after the name, the
  value starts on the next line. A received header name longer than 997
  characters can only come from a line already over 998 octets, so parsing such a
  block throws `Contenir\Mail\Header\Exception\RuntimeException`.
- A header that its class cannot parse, including an address header holding an
  invalid address, is kept as a `GenericHeader` with its original text, so one
  bad header no longer stops a message being read.
- Invalid UTF-8 in received header text is replaced with U+FFFD; laminas-mail
  replaced it with `?`.
- A new message gets a Message-ID on its sender's domain, and a message with several
  From addresses gets the first as its Sender. `addHeader()` refuses a second Date,
  From, Sender, Reply-To, To, Cc, Bcc, Message-ID, In-Reply-To, References or
  Subject; use `setHeader()` to replace one.
- SMTP refuses 8-bit content when the server does not offer 8BITMIME, and a
  multipart refuses a part that contains its boundary.
- Callable settings, such as the file transport's `callback`, accept a Closure or an
  invokable object, not a function name.
- Messages, headers and parts can be serialized for queues; a part's stream is
  serialized as its content.
- Address groups (`Team: a@example.org, b@example.org;`, `undisclosed-recipients:;`)
  are read with their names and can be written with `AddressGroup`; laminas-mail
  flattened them into their members.
- Addresses with an obsolete source route (`<@relay.example:jo@example.com>`) are
  read as the address, and the IMAP client never sends a password to a server that
  advertises `LOGINDISABLED`.
- Raw UTF-8 header values (RFC 6532) in stored or received mail are read into
  their header classes. Header values refuse control characters other than tab.
- A missing required storage setting (`dirname`, `filename`, `user`) throws
  `Contenir\Mail\Exception\InvalidArgumentException`.
- Display names are quoted when they contain RFC 5322 specials, and encoded with
  those specials escaped when they are not ASCII, so a name can never add
  recipients.
- Parsing an address-list header no longer reads a quoted display name containing
  `:` or `;` as group syntax, keeps addresses written before a group, and accepts
  a comment after a named address.
- Long `Content-Disposition` parameters with a long name are split into RFC 2231
  continuations instead of looping forever, and extended sections such as
  `filename*0*=` are read.
- `Imap` no longer hangs on a server response with two spaces in a row.
- `Storage\Writable\Maildir` quota checks no longer fail on the blank line after
  the last entry of a `maildirsize` file, which every rewrite of that file left.
- Maildir folders without a `new/` directory, which `Storage\Maildir` already
  accepted as valid, can now be opened and selected.
- Internationalised host names given to a protocol (SMTP, POP3, IMAP) are accepted
  whenever they convert to ASCII under UTS #46, not only under the TLDs
  laminas-validator kept character tables for.

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
