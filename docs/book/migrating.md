# Migrating from laminas-mail and laminas-mime

contenir-mail keeps the Zend_Mail and laminas-mail vocabulary: `Message`,
`Headers`, `Address`, MIME parts, transports, protocols and storage keep their
names and their roles. The API underneath is modernised for PHP 8.3, so it is not
a drop-in replacement: values are typed and immutable, and a few classes have
gone.

Most of the differences are loud: a method that no longer exists, or an argument
of the wrong type, fails the first time the code runs. This guide starts with the
ones that are not. Your code keeps running after the namespace rewrite, and does
the wrong thing.

- [Steps](#steps)
- [Silent changes: check these first](#silent-changes-check-these-first)
- [Mapping tables](#mapping-tables)
- [Removed](#removed)
- [Behaviour changes](#behaviour-changes)
- [Extending](#extending)

## Steps

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
   The factories need `psr/container`, which every PSR-11 container installs;
   contenir-mail only suggests it.

4. Work through the [silent changes](#silent-changes-check-these-first). Search
   your code for each one; your tests may not catch them.

5. Update code that touches the APIs in the [mapping tables](#mapping-tables).

## Silent changes: check these first

### Stored message flags are a list, not a map

**laminas-mail:** `Storage\Message::getFlags()` returned an array keyed by the
flag string, with the same string as the value: `['\Seen' => '\Seen']`.

**contenir-mail:** `getFlags()` returns a `list<Flag|string>`: a `Storage\Flag`
case for each standard flag and a string for each keyword, such as
`[Flag::Seen, '$Junk']`.

**If you don't change your code:** lookups by key are always false, and a
non-strict `in_array()` with a flag string is false too, because a `Flag` case
never equals a string. Read messages show as unread; `array_keys()` returns
`0, 1, …`.

**Fix:** ask the message with `hasFlag()`, which takes a case or an IMAP name.

```php
// laminas-mail
if (isset($message->getFlags()['\Seen'])) { … }
if (in_array('\Seen', $message->getFlags())) { … }

// contenir-mail
use Contenir\Mail\Storage\Flag;

if ($message->hasFlag(Flag::Seen)) { … }
if ($message->hasFlag('\Seen')) { … }        // the IMAP name works too
if ($message->hasFlag('$Junk')) { … }        // and keywords
```

`Storage::FLAG_SEEN` and the other constants are gone with the `Storage` class,
so code using them fails loudly; use the `Flag` cases.

### `Storage\Part::getContent()` returns the decoded body

**laminas-mail:** `getContent()` on a stored part returned the body as
transferred: still base64 or quoted-printable. You decoded it yourself, reading
`Content-Transfer-Encoding`.

**contenir-mail:** `getContent()` decodes it. `getEncodedContent()` returns the
body as transferred, as laminas-mail's `getContent()` did.

**If you don't change your code:** your `base64_decode()` runs over binary
data. Non-strict, it drops every byte outside the base64 alphabet and returns
garbage; strict, it returns `false`. `quoted_printable_decode()` over decoded text
changes any `=` followed by two hex digits. Attachments are saved corrupted, and
text with `=` in it is changed.

**Fix:** remove your decoding.

```php
// laminas-mail
$part = $message->getPart(2);
$data = match (strtolower($part->getHeaderField('Content-Transfer-Encoding'))) {
    'base64'           => base64_decode($part->getContent()),
    'quoted-printable' => quoted_printable_decode($part->getContent()),
    default            => $part->getContent(),
};

// contenir-mail
$data = $message->getPart(2)->getContent();
```

Two related differences: on a multipart, `getContent()` returns an empty string
(laminas-mail returned the raw body with every subpart in it; walk the parts
instead), and `(string) $part`, which returned laminas-mail's `getContent()`,
now fails, since parts are not `Stringable`. `$part->toString()` is not a
replacement: it returns the headers and the body.

### IMAP folder names are UTF-8

**laminas-mail:** folder names went to the server as given and came back from
LIST as the server sent them. To use a name outside ASCII you encoded it to
modified UTF-7 yourself, with `mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8')`
or `imap_utf7_encode()`, and decoded the names `getFolders()` returned.

**contenir-mail:** `Protocol\Imap` encodes names to modified UTF-7 (RFC 3501,
section 5.1.3) and decodes the names LIST returns. When the server has IMAP4rev2
or UTF8=ACCEPT enabled, names travel as UTF-8 and are not encoded at all. Either
way you give and get UTF-8.

**If you don't change your code:** names you encoded are encoded again. `&`
becomes `&-`, so `Entw&APw-rfe` is sent as `Entw&-APw-rfe`, a folder literally
named "Entw&APw-rfe". Selecting it fails or, with `createFolder()` or
`appendMessage()`, a new folder appears with that name and messages land in it.
With UTF-8 mailboxes enabled, your encoded name is likewise taken literally. On
the way back, decoding names that are already UTF-8 mangles anything outside
ASCII.

**Fix:** pass and expect UTF-8.

```php
// laminas-mail
$mail->selectFolder(mb_convert_encoding('Entwürfe', 'UTF7-IMAP', 'UTF-8'));

// contenir-mail
$mail->selectFolder('Entwürfe');
```

### `catch` blocks naming removed exception classes never match

After the namespace rewrite, a `catch` naming a class that no longer exists is
not an error. PHP does not load or check the class in a `catch`; the block simply
never matches, and the exception goes past it.

These laminas exception classes are gone:

| laminas-mail | Catch instead |
| --- | --- |
| `Storage\Part\Exception\ExceptionInterface`, `InvalidArgumentException`, `RuntimeException` (thrown by `Part\File`, the parts of mbox and maildir messages) | `Storage\Exception\ExceptionInterface`, or `Storage\Exception\RuntimeException` and `OutOfBoundsException` (for a part number that does not exist) |
| `Header\Exception\BadMethodCallException` (never thrown by laminas-mail) | `Header\Exception\ExceptionInterface` |

Every component's `ExceptionInterface` (`Header`, `Mime`, `Protocol`, `Storage`,
`Transport`) extends `Contenir\Mail\Exception\ExceptionInterface`, so catching
that catches everything the package throws. In laminas, the `Laminas\Mime`
exceptions did not share an interface with `Laminas\Mail`; now they do.

```php
// laminas-mail
try {
    $part = $message->getPart(3);
} catch (Laminas\Mail\Storage\Part\Exception\RuntimeException $e) { … }

// contenir-mail
try {
    $part = $message->getPart(3);
} catch (Contenir\Mail\Storage\Exception\ExceptionInterface $e) { … }
```

### Values are immutable: a discarded result changes nothing

**laminas-mail:** `Headers`, header classes and address lists were changed in
place. `$message->getHeaders()->addHeader($h)` changed the message.

**contenir-mail:** `Headers`, every header class, `Address` and `AddressList`
are immutable. Methods such as `Headers::with()`, `withAdded()` and `without()`,
or `ContentType::withParameter()`, return a new value and leave the old one as
it was.

**If you don't change your code:** a call whose result you don't keep does
nothing, and the header is never sent. PHP 8.5 warns when the result of a
`with*()` or `without*()` method is discarded, as they carry `#[\NoDiscard]`;
PHP 8.3 and 8.4 don't.

**Fix:** change the message through its own methods, or keep the result and
give it back.

```php
// does nothing
$message->getHeaders()->with(new GenericHeader('X-Id', '1'));

// contenir-mail
$message->setHeader(new GenericHeader('X-Id', '1'));                     // replace
$message->addHeader(new GenericHeader('X-Id', '1'));                     // append
$message->setHeaders($message->getHeaders()->with($header));            // keep the result
```

The same applies to `$message->getTo()`: use `$message->addTo()` rather than
adding to the list it returns.

### `security: 'tls'` means TLS from the start, not STARTTLS

**laminas-mail:** the `ssl` setting took `'ssl'` for TLS from the start (port
465, 993 or 995) and `'tls'` for STARTTLS (port 587 or 143).

**contenir-mail:** the `security` setting takes `'tls'` for TLS from the start,
`'starttls'` (the default) and `'none'`. That is, `security: 'tls'` is what
`ssl: 'ssl'` was.

**If you don't change your code:** if you rename the key and keep the value,
`ssl: 'tls'` becomes `security: 'tls'`, and the client opens a TLS handshake on
a port that expects plain text and STARTTLS. The connection fails or hangs until
the timeout. IMAP and POP3 still accept the legacy `ssl` key with its laminas
meaning, so for them leaving `ssl` as it was is safe; the SMTP transport only
accepts `security`, and refuses `ssl` and `connection_config` as unknown keys.

**Fix:**

| laminas-mail | contenir-mail |
| --- | --- |
| `'ssl' => 'ssl'` | `'security' => 'tls'` |
| `'ssl' => 'tls'` | `'security' => 'starttls'`, or leave it out |
| no `ssl` | `'security' => 'none'` (see the next section) |

### Defaults that change how mail is sent

These defaults differ from laminas-mail. Each one is a deliberate fix, but each
changes behaviour for code that relied on the old default.

- **STARTTLS is required.** laminas-mail connected in plain text unless told
  otherwise. Now SMTP, IMAP and POP3 require STARTTLS, and fail rather than
  continue in plain text if the server does not offer it. A local relay without
  TLS, such as `localhost:25`, needs `'security' => 'none'`.
- **SMTP connects to port 587.** Without a port, laminas-mail used `smtp_port`
  from php.ini, or 25. Now SMTP uses 587 for STARTTLS, 465 for TLS and 25 for
  `none`. An omitted `$ssl` to the positional `Protocol\Imap` and `Pop3`
  constructors also means STARTTLS now.
- **Certificates are verified.** The server's certificate must be valid for the
  host name, as PHP's defaults already required in laminas-mail. A relay with a
  self-signed certificate needs `cafile` pointing at the certificate authority,
  or `allow_self_signed`; `verify_peer: false` turns verification off.
- **SMTP AUTH needs an encrypted connection.** Credentials over a plain
  connection are refused unless `allow_insecure_auth` is set.
- **New messages get a Message-ID.** laminas-mail sent none unless you added one,
  leaving it to the server. A `Message` you build now gets one on its Sender's or
  first From address's domain, or on a reserved domain. Messages parsed with
  `Message::fromString()` and messages given their headers with `setHeaders()`
  are not given one. Remove the header with `removeHeader('Message-ID')` if your
  server must assign it.
- **Several From addresses add a Sender.** RFC 5322 requires a Sender when a
  message has more than one From address. If you set none, the first From
  address is written as the Sender header; the SMTP envelope sender is the same
  as before.
- **Headers allowed once are refused twice.** `Message::addHeader()` throws for a
  second Date, From, Sender, Reply-To, To, Cc, Bcc, Message-ID, In-Reply-To,
  References or Subject; laminas-mail sent both. Use `setHeader()` to replace one.
  This one is loud, but only on the code path that adds the second header.

### `Mime\Part` defaults to base64

**laminas-mime:** a `new Part($content)` was sent `8bit` unless you set
`$part->encoding`.

**contenir-mail:** `new Mime\Part($content, …)` defaults to
`TransferEncoding::Base64`.

**If you don't change your code:** text parts built with the constructor are
sent base64. They still decode correctly, but the message source is no longer
readable, and anything that searches the raw message for the text, such as a
test, a content filter or a log grep, stops finding it.

**Fix:** build text with `Part::text()` and `Part::html()`, which use
quoted-printable, or pass the encoding.

```php
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Mime\TransferEncoding;

$part = Part::text('Hello');                                                        // quoted-printable
$part = new Part('Hello', type: 'text/plain', encoding: TransferEncoding::EightBit); // as laminas-mime sent it
```

### Extra arguments are ignored without error

PHP ignores extra arguments to a method that declares fewer parameters, so a
call that still passes a removed parameter runs and does something else.

**laminas-mail:** `getRawHeader($id, $part, $topLines)` and
`getRawContent($id, $part)` on a storage threw "not implemented" for any `$part`.

**contenir-mail:** `getRawHeader($id)` and `getRawContent($id)` take only the
message number.

**If you don't change your code:** a call with a `$part` no longer throws; it
returns the whole message's header block or body.

**Fix:** read the part from the message.

```php
$body = $mail->getMessage($number)->getPart(2)->getEncodedContent();
```

### Class names whose case changed

`Protocol\Smtp\Auth\Crammd5` is now `CramMd5`, and `Protocol\Smtp\Auth\Xoauth2`
is now `XOAuth2`. PHP class names are case-insensitive, and macOS's default file
system is too, so the old spelling works on a developer's Mac. On Linux, Composer's
PSR-4 autoloader looks for `Crammd5.php`, does not find `CramMd5.php`, and the
class is not found in production. Use the new spelling.

## Mapping tables

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
| `$headers->clearHeaders()` | `new Headers()` |
| `$headers->forceLoading()` | Not needed: headers are parsed when they are read |
| `$header->setEncoding('UTF-8')`, `$message->setEncoding('UTF-8')` | Headers encode themselves: plain ASCII stays readable, anything else is RFC 2047 encoded as UTF-8. A body's character set is given with its text: `setText($text, 'ISO-8859-1')` |
| `$header->getFieldValue(HeaderInterface::FORMAT_ENCODED)` | `$header->getEncodedFieldValue()` |
| `new Subject(); $subject->setSubject('Hi')` and other setters | Constructors: `new Subject('Hi')`, `new ContentType('text/plain', ['charset' => 'UTF-8'])`, `new Date(new DateTimeImmutable())` |
| `$contentType->addParameter('charset', 'UTF-8')` | `$contentType->withParameter('charset', 'UTF-8')` |
| `new ContentTransferEncoding(); ->setTransferEncoding('base64')` | `new ContentTransferEncoding(TransferEncoding::Base64)` |
| `$messageId->setId()` (generated) | `MessageId::generate()` |
| `$references->setIds([...])` | `new References(...$ids)` |
| `AddressList::add()`, `addMany()`, `merge()`, `delete()` | `with()`, `fromIterable()`, `withList()`, `without()` |
| `Address\AddressInterface` | `Address` (a `final readonly` value) |
| An address servers accept but RFC 5322 refuses, such as `first..last@example.com` ([#64](https://github.com/laminas/laminas-mail/issues/64), [#146](https://github.com/laminas/laminas-mail/issues/146), [#148](https://github.com/laminas/laminas-mail/issues/148)) | `new Address('first..last@example.com', 'Jo', strict: false)`; strings and parsed mail stay strict |
| `Header\HeaderLocator::add()` / `remove()` | `new HeaderLocator(['x-name' => MyHeader::class])` or `->with()`, passed to `Headers::fromString()` |
| `GenericMultiHeader`, `MultipleHeadersInterface`, `StructuredInterface`, `UnstructuredInterface` | Removed; `Headers` keeps repeated headers such as `Received` as separate entries |
| `IdentificationField` | `AbstractIdentificationField` |

### Custom header classes

A header class of your own implements `Header\HeaderInterface`, which changed:

| laminas-mail `HeaderInterface` | contenir-mail `HeaderInterface` |
| --- | --- |
| `FORMAT_ENCODED`, `FORMAT_RAW` constants | Removed |
| `getFieldValue($format = self::FORMAT_RAW)` | `getFieldValue(): string`, the decoded value |
| (none) | `getEncodedFieldValue(): string`, the value as written in the message, RFC 2047 encoded where needed; required |
| `setEncoding($encoding)`, `getEncoding()` | Removed |
| `static fromString($headerLine)` | `static fromString(string $headerLine): static` |
| `getFieldName()`, `toString()` | `getFieldName(): string`, `toString(): string` |

Register the class with a `HeaderLocator`, as in the table above, for it to be
used when headers are parsed.

### MIME bodies

`Message` builds the MIME structure itself, choosing `multipart/alternative`,
`related` and `mixed` as the parts require and adding `MIME-Version` and
`Content-Type` with the boundary. MIME parts are immutable values.

| laminas-mail and laminas-mime | contenir-mail |
| --- | --- |
| `new Mime\Message()`, `setParts()`, `$message->setBody($mimeMessage)` plus a hand-set `Content-Type` | `$message->setText()`, `setHtml()`, `attach()`, `embed()` |
| A `Mime\Message` rendered into a `Mime\Part` to nest `multipart/alternative` | `new Multipart(MultipartType::Alternative, [...])`, nested directly |
| `$part = new Part($c); $part->type = ...; $part->filename = ...` | `new Part($c, type: ..., filename: ...)`, `Part::text()`, `Part::html()` |
| `Part` with the default `8bit` encoding | `Part` defaults to base64; see [above](#mimepart-defaults-to-base64) |
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
| `Transport\SmtpOptions`, `setOptions()` | `Transport\SmtpConfig`, or an array given to `new Smtp([...])` |
| `connection_class` with `connection_config` `username` and `password` | `auth`: an authenticator, or `['type' => 'login', 'username' => ..., 'password' => ...]` |
| `connection_config['ssl']` = `'ssl'` / `'tls'` | `security` = `'tls'` / `'starttls'` (the default); `'none'` for a plain connection. See [above](#security-tls-means-tls-from-the-start-not-starttls) |
| `connection_config['novalidatecert']` | `verify_peer` |
| `Protocol\Smtp\Auth\Plain`, `Login`, `Crammd5`, `Xoauth2` (subclasses of `Protocol\Smtp`) | `Protocol\Smtp\Auth\Plain`, `Login`, `CramMd5`, `XOAuth2`, implementing `AuthenticatorInterface` |
| `Protocol\SmtpPluginManager`, `Transport\Smtp::setPluginManager()` / `plugin()` | Removed; implement `AuthenticatorInterface` for another mechanism |
| `Transport\FileOptions` | `Transport\FileConfig` |
| `new Sendmail($parameters)`, `setParameters()`, `setCallable()` | `new Sendmail($parameters)` or a `SendmailConfig`, with `mailer:` for a custom callable |
| `Transport\Factory::create($spec)` | `Container\TransportFactory`, reading `config['mail']['transport']` with a `type` key, or construct the transport |
| `MessageFactory::getInstance($options)` | Removed; use the `Message` setters |
| `ConfigProvider::getDependencyConfig()` | `ConfigProvider::getDependencies()` |
| `Transport\Envelope` (options object) | `new Envelope(from: ..., to: ...)`, readonly and validated |

### IMAP and POP3 protocols

| laminas-mail | contenir-mail |
| --- | --- |
| `new Protocol\Imap($host, $port, $ssl)`, the same for `Pop3` | The same, or a `ConnectionConfig`; an omitted `$ssl` now means STARTTLS |
| `$ssl = true` or an unknown string | Throws; use `'ssl'`, `'tls'`, `'none'` or `false` |
| Folder names encoded to modified UTF-7 by the caller | UTF-8; see [above](#imap-folder-names-are-utf-8) |
| Socket handling inside the protocol classes | `ConnectionInterface`, with `StreamConnection` and `Testing\InMemoryConnection` |
| laminas-stdlib `ErrorHandler` | Internal; no laminas-stdlib dependency |
| Commands returning raw response lines | `login()`, `select()`, `store()` and the other commands are typed; boolean commands return `bool` |
| Unlimited response sizes | `ResponseLimits` (8 MiB lines, 64 MiB responses by default), set with `setResponseLimits()` |

### Storage

| laminas-mail | contenir-mail |
| --- | --- |
| `$params` arrays read by `ParamsNormalizer` | `MboxConfig`, `MaildirConfig`, `ImapConfig`, `Pop3Config`; arrays still work |
| `Storage::FLAG_SEEN` and the other constants | The `Storage\Flag` enum; IMAP flag strings are accepted where flags are given |
| `$message->getFlags()` keyed by flag, `isset($flags['\Seen'])` | `$message->hasFlag(Flag::Seen)`; see [above](#stored-message-flags-are-a-list-not-a-map) |
| `$message->subject` and other magic header properties | `getSubject()`, `getFrom()`, `getDate()`, `getHeaders()` |
| `isset($message->cc)` | `$message->getHeaders()->has('cc')` |
| `$part->getHeader($name)` | `$part->getHeaders()->get($name)`, which returns `null` rather than throwing when there is none |
| `$part->getHeader($name, 'string')` | `$part->getHeaders()->get($name)?->getFieldValue()` |
| `$part->getHeader($name, 'array')` | `$part->getHeaders()->all($name)` |
| `$part->getHeaderField('Content-Type', 'charset')` | `$part->getCharset()`, or `$part->getHeaders()->get('Content-Type')?->getParameter('charset')` |
| `$part->getHeaderField('Content-Disposition', 'filename')` | `$part->getFilename()`, or `getSafeFilename()` for a name safe to use on disk |
| `$part->getHeaderField('Content-Type')` | `$part->getContentType()` |
| `(string) $part`, `$part->__toString()` | `$part->getEncodedContent()` (what it returned), or `getContent()` decoded |
| `$part->getContent()` returning the transferred text | `getContent()` returns it decoded; `getEncodedContent()` returns it as transferred. See [above](#storagepartgetcontent-returns-the-decoded-body) |
| `new Storage\Message(['raw' => $raw, 'flags' => $flags])` | `Storage\Message::fromString($raw, $flags)` |
| `new Storage\Message(['file' => $path])`, `Storage\Message\File`, `Storage\Part\File` | `Storage\Message::fromString(file_get_contents($path))`; mbox and maildir storages read their files themselves |
| `Storage\Message\MessageInterface`, `Storage\Part\PartInterface` | `Storage\Message` and `Storage\Part`, which implement `Mime\PartInterface` |
| `$storage[3]`, `unset($storage[3])` | `getMessage(3)`, `removeMessage(3)` |
| `$storage->seek($n)`, `new LimitIterator($storage, …)` | `getMessage($n)` for each number, or `Storage\Imap::getMessages(...$numbers)` to fetch a page in one request |
| `getRawHeader($id, $part, $topLines)`, `getRawContent($id, $part)` | `getRawHeader($id)`, `getRawContent($id)`; see [above](#extra-arguments-are-ignored-without-error) |
| `protected $messageClass` in a storage subclass | Removed; see [Extending](#extending) |
| `$folders->INBOX->Archive` | `$mail->getFolders()->getFolder('INBOX')->getFolder('Archive')` |
| `getSize()` and `getUniqueId()` without a number, returning every message | `getSizes()` and `getUniqueIds()` |
| `hasTop`, `hasFlags` and the other `has*` properties | `getCapabilities()`, such as `getCapabilities()['top']` |
| `messageEOL` setting | Not needed: line breaks are detected |
| `serialize($mbox)` to cache a storage | Not supported: storages hold open files and connections |
| `getTopLines()`, `Storage::FLAG_UNSEEN` | Removed |

Reading mail is more forgiving: a header that its class cannot parse, such as a
malformed `Date`, is kept as a `GenericHeader` rather than making the whole
message unreadable.

## Removed

- The legacy `Zend\Mail\*` service names, and `SmtpPluginManager` with all its
  aliases.
- The `laminas/laminas-stdlib`, `laminas/laminas-servicemanager` and
  `webmozart/assert` dependencies.
- The `TESTS_LAMINAS_MAIL_*` test environment variables, now `TESTS_CONTENIR_MAIL_*`.
- `Headers::setPluginClassLoader()`, `Headers::getPluginClassLoader()` and
  `Header\HeaderLoader`, deprecated since laminas-mail 2.12, together with the
  abandoned `laminas/laminas-loader` dependency.
- The `laminas/laminas-validator` dependency. Addresses and host names are checked
  by internal validators that follow laminas-validator 2's `EmailAddress` and
  `Hostname` rules, so an application is free to use either laminas-validator
  major version. They differ in three ways. Internationalised host names are
  accepted whenever they convert to ASCII under UTS #46, not only under the TLDs
  laminas-validator kept character tables for. They must also pass the IDNA2008
  bidi and CONTEXTJ rules, which reject look-alike labels. And the top-level
  domain is checked for its form (letters, or an `xn--` label), not against a
  list of TLDs. `AbstractProtocol::$validHost` is now a
  `Contenir\Mail\Validator\HostnameValidator` rather than a `ValidatorChain`.
- The exception classes listed [above](#catch-blocks-naming-removed-exception-classes-never-match).

## Behaviour changes

The defaults and silent changes above are not repeated here.

- `HeaderWrap::mimeDecodeValue()` no longer uses ext-imap, which left PHP core in
  8.4. A built-in RFC 2047 decoder handles multibyte characters split across
  encoded words, words in any charset case, and tab-folded lines.
- SMTP only uses authentication mechanisms the server advertises.
- SMTP refuses a body line longer than 998 octets instead of folding it, which
  changed the content and broke DKIM signatures.
- Text parts write their line breaks as quoted-printable hard line breaks and keep
  trailing whitespace, and `multipart/related` names its root type.
- Headers read from stored mail write back the exact text they were read with
  until they are changed, so forwarded and DKIM-signed headers survive.
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

## Extending

Most classes are `final`: `Message`, `Headers`, the header and address values,
the MIME parts, every transport, `Protocol\Smtp`, `Storage\Message`,
`Storage\Part`, `Storage\Imap` and `Storage\Pop3`. A subclass of one of these in
your code fails loudly; replace it with composition.

- **Transports:** implement `Transport\TransportInterface`, whose one method is
  `send(Message $message): void`, and wrap the real transport to add logging,
  retries or a fallback.
- **Connections:** implement `Protocol\ConnectionInterface` to change how bytes
  reach the server, for a proxy, a recording or a test; `StreamConnection` is the
  default and `Testing\InMemoryConnection` plays a script.
- **SMTP authentication:** implement `Protocol\Smtp\Auth\AuthenticatorInterface`
  for a mechanism the package lacks.
- **Header classes:** implement `Header\HeaderInterface` and register the class
  with a `HeaderLocator`.

These classes stay open:

| Class | Why |
| --- | --- |
| `Protocol\Imap`, `Protocol\Pop3` | A storage takes a protocol object, so a subclass can add commands or steps, such as port knocking before connecting. See [Extending protocol classes](read.md#extending-protocol-classes) |
| `Protocol\AbstractProtocol` | The base `Protocol\Smtp` builds on, with the protected API kept from laminas-mail |
| `Storage\AbstractStorage` | The base of every storage |
| `Storage\Mbox`, `Storage\Maildir`, `Storage\Folder\Maildir` | Extended by the folder and writable storages of the package |
| `Header\AbstractAddressList`, `Header\AbstractIdentificationField` | The bases of the address headers (From, To, Cc, Bcc, Reply-To) and of In-Reply-To and References |

**The `messageClass` hook is gone.** laminas-mail storages built their messages
from a `protected $messageClass`, so a subclass could return its own message
class. Storages now always return `Storage\Message`, which is final. Wrap it
instead:

```php
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\Message;

final class Ticket
{
    public function __construct(private readonly Message $message) {}

    public function isAnswered(): bool
    {
        return $this->message->hasFlag(Flag::Answered);
    }

    public function message(): Message
    {
        return $this->message;
    }
}

foreach ($mail as $number => $message) {
    $ticket = new Ticket($message);
}
```
