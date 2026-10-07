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

See the [documentation](docs/book/index.md) for transports, attachments,
character sets and reading mail.

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
   (laminas-mvc) in place of the Laminas ones.

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
| `Mime\Message::createFromMessage()` | `Mime\Decode` and the storage classes, until parsing returns `PartInterface` trees |
| `Part::isStream()`, `getEncodedStream()` | A stream given as content is read and encoded in chunks when the message is written |
| `Message::getEncoding()` | Removed |
| `Date` header from the current time | From an injected PSR-20 clock: `new Message(clock: $clock)` |

Reading mail is more forgiving: a header that its class cannot parse, such as a
malformed `Date`, is kept as a `GenericHeader` rather than making the whole
message unreadable.

### Removed

- The legacy `Zend\Mail\*` service names, and the normalised `zendmail*` and
  `laminasmail*` aliases of `SmtpPluginManager`. Use the class names or the short
  names (`smtp`, `login`, `plain`, `crammd5`, `xoauth2`).
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
- A failed `AbstractProtocol::_connect()` no longer leaves its temporary error
  handler installed.
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
