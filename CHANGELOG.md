# Changelog

All notable changes to this project are documented in this file, in the
format of [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The
project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.3.1 - TBD

### Added

- Nothing.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 0.3.0 - 2026-10-09

### Added

- TLS settings beyond `verify_peer`, for SMTP, IMAP and POP3: `cafile` and
  `capath` to trust a private certificate authority, `peer_name` to check a
  name other than the host, `allow_self_signed`, and `local_cert` and
  `local_pk` for a client certificate. They're typed fields of the new
  `Protocol\TlsConfig` (`caFile`, `caPath`, `peerName`, `allowSelfSigned`,
  `localCert`, `localPrivateKey`), given as `ConnectionConfig::$tls` or as settings in
  any config, and they apply to TLS from the start and to STARTTLS. No other
  ssl context option can be passed, and peer verification stays on unless
  `verify_peer` turns it off. (#16)
- SMTP PIPELINING (RFC 2920): when the server offers it, MAIL and every RCPT
  are sent together and the replies read after, saving a round trip for each
  recipient. DATA stays a step of its own. `Protocol\Smtp::envelope()` starts
  the transaction either way and the SMTP transport uses it. Every reply is
  read before a refusal is reported, and the transaction is then reset with
  RSET. (#19)
- `new Address($email, strict: false)` builds an address real mail servers
  take but RFC 5322 refuses, such as one with consecutive or trailing dots in
  the local part, or an underscore in the host. It still needs one `@` and a
  domain, and refuses whitespace, control characters and the specials that
  could break a header or an SMTP command. Only addresses built this way are lenient, and they stay
  so in messages, headers and address lists; `Address::isStrict()` tells
  which check an address passed. Strings and reading stay strict. (#18)
- IMAP4rev2 (RFC 9051): after signing in, `Protocol\Imap` turns on IMAP4rev2
  when the server offers it, or else UTF8=ACCEPT (RFC 6855), with ENABLE
  (RFC 5161). Mailbox names then travel as UTF-8. SEARCH reads ESEARCH
  results, bounded to `Imap::MAX_SEARCH_RESULTS`. Folders listed as
  `\NonExistent` can't be selected. `preferImap4Rev2(false)` turns this off.
  New: `enable()`, `hasCapability()`, `usesUtf8MailboxNames()`, and `move()`,
  which `Storage\Imap::moveMessage()` uses when the server offers MOVE
  (RFC 6851). (#14)
- Paging through large IMAP folders: `Storage\Imap::getSortedNumbers()` has the
  server sort the folder (RFC 5256 SORT, RFC 5957 display keys), and
  `getMessages(...$numbers)` fetches the flags and headers of a page in one
  FETCH, each body only when it's read. `countMessages()` asks the server for
  the count with ESEARCH (RFC 4731) when it can. New on `Protocol\Imap`:
  `sort()` and `searchCount()`. (#21)
- Reading TNEF (`winmail.dat`) attachments: `Storage\Part::getTnefContents()`
  and `Storage\Message::getTnefContents()` find a message's
  `application/ms-tnef` part and return its attachments, its plain-text body
  and its RTF body, decompressed. `Storage\Tnef\Reader` reads a container
  directly. The container is parsed as hostile: lengths are bounds-checked,
  checksums verified, and attachments and output limited (100 and 64 MiB by
  default). (#22)
- SCRAM-SHA-256 authentication (RFC 5802, RFC 7677) for SMTP, IMAP and POP3.
  `Protocol\Smtp\Auth\ScramSha256` is an SMTP authenticator, with `type`
  `scram-sha-256` in settings, and `Imap::authenticate()` and
  `Pop3::authenticate()` take it as they take `XOAuth2`. `ImapConfig` and
  `Pop3Config` accept it under `auth`, as an object or as settings with a
  `type`; settings without one are still read as XOAUTH2. The server's
  signature is verified, and the exchange fails closed without it. Iteration
  counts outside 4096 to 1,000,000 are refused. Non-ASCII credentials are
  normalised to NFKC when intl is installed. Channel binding (`-PLUS`) is not
  supported, as PHP does not expose the TLS data it needs. (#20)
- DKIM signing (RFC 6376), with `rsa-sha256` and `ed25519-sha256` (RFC 8463).
  `Dkim\Signer::sign()` returns a copy of the message with a `DKIM-Signature`
  header first, holding the headers and CRLF body exactly as they were signed
  and as the transports send them. Settings are a `Dkim\DkimConfig`: domain,
  selector, key, signed headers (From always), `relaxed` or `simple`
  canonicalisation, and optional `i=`, `t=`, `x=` and `l=`. `Dkim\PrivateKey`
  reads RSA and Ed25519 keys from PEM, files, OpenSSL keys or raw Ed25519
  seeds, refuses RSA keys under 1024 bits, hides the key from dumps and gives
  the DNS record to publish. `Headers::withFirst()` adds a header before the
  others. (#23)
- IMAP UIDPLUS (RFC 4315): `Storage\Imap::appendMessage()`, `copyMessage()`
  and `moveMessage()` return the UID the message has in the destination
  folder, from the APPENDUID or COPYUID response code; for MOVE, from the
  untagged COPYUID it sends before expunging. New on `Protocol\Imap`:
  `appendReturningUids()`, `copyReturningUids()` and `moveReturningUids()`,
  which return a `Protocol\Imap\UidMapping` with the UIDVALIDITY and the
  source and destination UIDs, or null when the server sends none, and throw
  the new `Protocol\Exception\CommandRefusedException` when it refuses, as
  `searchCount()` does. UID sets are validated strictly and bounded to
  `UidMapping::MAX_UIDS`; a malformed code is ignored. (#52)
- IMAP UNSELECT (RFC 3691): `Protocol\Imap::unselect()` leaves the selected
  folder without expunging messages flagged `\Deleted`, when the server
  offers UNSELECT or IMAP4rev2 is enabled. (#52)
- IMAP LITERAL+ and LITERAL- (RFC 7888): literals are sent without waiting
  for the server's `+` when it offers LITERAL+, or LITERAL- or IMAP4rev2 and
  the literal is at most 4096 bytes. (#52)
- IMAP folder metadata. SPECIAL-USE (RFC 6154): `Storage\Folder::getSpecialUse()`
  returns the `Storage\SpecialUse` a server marks a folder with, such as
  `SpecialUse::Sent`, and `Storage\Imap::getSpecialFolder()` finds the folder
  with a use. NAMESPACE (RFC 2342): `Protocol\Imap::namespace()` and
  `Storage\Imap::getNamespaces()` return the personal, other users' and shared
  namespaces as `Protocol\Imap\Namespaces`. STATUS: `Protocol\Imap::status()`
  reads a mailbox's status without selecting it. `Storage\Imap::getFolderStatus()`
  returns a `Storage\FolderStatus` with its `messageCount`, `unseenCount`,
  `uidNext` and `uidValidity`, the last to store with any UID kept.
  `getFolderSize()` returns its size when the server offers STATUS=SIZE
  (RFC 8438) or IMAP4rev2. New: `Protocol\Imap::isImap4Rev2Enabled()`. Responses with
  lists next to each other without a space, such as `(("" "/")("#shared/" "/"))`,
  are now tokenised as separate lists. (#52)
- The `with*()` and `without*()` methods of the immutable classes
  (`Headers`, `AddressList`, `Mime\Body`, `Header\ContentType`,
  `Header\ContentDisposition`, the address-list headers and
  `Header\HeaderLocator`) carry `#[\NoDiscard]`, so PHP 8.5 warns when the
  copy they return is thrown away, as when one is mistaken for a setter.
  Earlier PHP versions ignore the attribute.
- IMAP IDLE (RFC 2177): `Storage\Imap::idle($timeout)` is a generator of
  `Storage\Idle` events for the selected folder: `MessageCountChanged` when
  mail arrives, `MessageExpunged`, `RecentCountChanged` and `FlagsChanged`,
  all `Idle\EventInterface`. It stops after the timeout, 29 minutes by
  default, which RFC 2177 advises; call it again to keep listening. DONE is
  sent and the reply read when the timeout passes, when the loop is left
  early, or before the next command, so the connection stays usable. BYE is thrown. `Protocol\Imap::idle()` yields the
  raw untagged responses; both take a PSR-20 clock. Waiting uses the new
  `ConnectionInterface::waitUntilReadable()`, which `StreamConnection` and
  `Testing\InMemoryConnection` implement; a stall in an `InMemoryConnection`
  script ends a wait, and `waits()` lists how long the client waited. (#52)
- Writing a message to a stream: `Message::writeTo($stream)` and
  `writeBodyTo($stream)` write the bytes `toString()` and `getBodyText()`
  return, and `Mime\PartWriter::write($part, $stream)` those of `body()`, a
  piece at a time. `Mime\Part::encodedChunks()` gives a part's encoded
  content in pieces, base64 of a stream a read at a time, so an attachment
  read from a stream is never held in memory as a whole.
  `Protocol\Smtp::dataFromStream($stream)` sends DATA from a seekable stream.

### Changed

- `WritableInterface::appendMessage()`, `copyMessage()` and `moveMessage()`
  return `?int` instead of `void`: the UID the message has in the
  destination folder when the storage reports one, and null otherwise.
  `Storage\Writable\Maildir` returns null. Classes implementing the
  interface must change their return types. `Storage\Imap` now calls
  `Protocol\Imap::appendReturningUids()`, `copyReturningUids()` and
  `moveReturningUids()`, so a protocol subclass that overrides `append()`,
  `copy()` or `move()` must override those instead. (#52)
- `Protocol\ConnectionInterface` has a new method,
  `waitUntilReadable(int $seconds): bool`, for IMAP IDLE. A connection implemented outside the package must add it:
  return whether the server has sent something, or closed the connection,
  within that many seconds. (#52)
- The migration guide moved to `docs/book/migrating.md` and lists the silent
  changes first.
- The SMTP and File transports write the message as it is made instead of
  building it as a string: SMTP to `php://temp`, sent from there, and File
  straight to its file, which is removed if it cannot be finished. Sending a
  28 MB message with a 20 MB attachment over SMTP took 5 s and 106 MB of
  memory; it takes 0.3 s and 2.5 MB. File takes 0.15 s and 1.2 MB instead of
  0.3 s and 84 MB, and `toString()` 80 ms and 56 MB instead of 180 ms and
  84 MB.
- `Protocol\Smtp::data()` writes the message in 64 KiB chunks, with line
  endings and leading dots fixed a chunk at a time, instead of one write and
  one log entry per line. The log holds `[DATA n bytes]` in place of the
  message text. Over-long lines are still refused before anything is sent.
- `StreamConnection::write()` writes at most 64 KiB at a time and no longer
  copies the rest of the data after each partial write.
- Quoted-printable encoding replaces characters in one pass over the text
  instead of a pass per character per line, three times faster for text.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- IMAP mailbox names are written in modified UTF-7 (RFC 3501, section 5.1.3)
  and decoded from LIST. Folder names outside ASCII, or with `&`, were sent
  and returned raw, which IMAP4rev1 servers refuse or misread. Names are now
  given and returned as UTF-8 throughout. (#14)
- `Protocol\Imap::login()` kept the capabilities the server listed before
  signing in. Servers list more once signed in (Dovecot adds MOVE, SORT,
  ESEARCH and UNSELECT), so after a password login `moveMessage()` copied and
  expunged instead of using MOVE. The capabilities are now asked for again,
  as they already were after AUTHENTICATE. (#52)
- Secrets no longer show in exception traces. With
  `zend.exception_ignore_args` off, as in development, a failed IMAP LOGIN
  showed the password in three frames, and a rejected setting showed the
  whole config array. Every parameter that can carry a password, token, key
  or SASL response, from `ConnectionInterface::write()` and the command
  builders to `ConfigReader` and the transport factory, is now marked
  `#[SensitiveParameter]`, and `ConfigReader` hides its values from
  `var_dump()`.
- Unfolding a header took time quadratic in its continuation lines: one
  folded over 80,000 lines took 17 seconds to read. It is now linear (40 ms).
- The IMAP tokenizer copied the rest of the line for every token, so a SEARCH
  reply of 200,000 ids took 9 seconds to read. It now reads each line in one
  pass (100 ms), lists side by side included.
- A sequence set of about 10,000 ranges or more exhausted PCRE's stack: an
  ESEARCH result was refused as malformed, and `fetch()`, `store()`,
  `copy()` and `move()` refused a valid set. Each range is now checked on its
  own.
- `Mime::CHARSET_REGEX` meant to allow `_` and `` ` `` in a charset name but
  allowed the bytes `\x05`, `f` and `0`, so `mimeDetectCharset()` read
  `=?ISO_8859-1?Q?caf=E9?=` as ASCII.
- A MIME boundary is now 128 random bits from `random_bytes()`, instead of a
  hash of `uniqid()`, which a sender could predict.

## 0.2.1 - TBD

### Added

- Nothing.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 0.2.0 - 2026-10-08

### Added

- `Storage\Message` and `Storage\Part` gain `getTextBody()` and
  `getHtmlBody()`, which return the first non-attachment `text/plain` or
  `text/html` part as UTF-8, or null. Transfer encodings and allow-listed
  charsets are decoded, and the last `multipart/alternative` part wins. The
  HTML is returned as sent and is not sanitised.
- `Storage\Imap::addFlags()` and `removeFlags()` add or remove flags on a
  message with one `STORE +FLAGS.SILENT` or `-FLAGS.SILENT` command, leaving
  its other flags alone. They are not on `WritableInterface`, as Maildir has
  no equivalent.
- IMAP and POP3 sign in with an OAuth 2.0 access token (XOAUTH2), for Gmail and
  Microsoft 365. Give `ImapConfig` or `Pop3Config` an `auth` setting: the same
  `XOAuth2` authenticator SMTP uses, as an object or as `username` and
  `access_token`, with a Closure for a fresh token at each sign-in.
  `Protocol\Imap::authenticate()` uses SASL-IR when the server offers it.
  `Protocol\Pop3::authenticate()` takes the place of `Pop3\Xoauth2\Microsoft`,
  which still works. `XOAuth2::initialResponse()` gives the SASL response. (#32)

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 0.1.2 - TBD

### Added

- Nothing.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 0.1.1 - 2026-10-08

### Added

- An integration suite that runs against Dovecot, Postfix and Mailpit in CI
  (`tests/Integration`), and a smoke test for real providers with your own
  account (`tests/Smoke`).

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- `Protocol\Imap::store()` returns an empty array when it is not silent and the server
  reports no changed flags, as Dovecot does for flags a message already has. It
  returned `true`, which callers reading the new flags could not use.
- `Storage\Pop3::getCapabilities()` reports whether the server has TOP and UIDL,
  asking on the first call. Both stayed `null` since the magic `hasTop` and
  `hasUniqueId` properties were removed.
- A refused XOAUTH2 token now ends the SASL exchange with the empty response
  RFC 7628 requires, for SMTP and POP3, and is reported as "The server refused
  the access token" with the status the server gave and its final reply, such as
  Gmail's "Your account is not enabled for POP access". The client used to stop at
  the server's challenge, leaving the session waiting and reporting raw base64.

## 0.1.0 - 2026-10-08

First release. contenir-mail continues laminas-mail and laminas-mime for
PHP 8.3, 8.4 and 8.5, under the `Contenir\Mail` namespace.

### Added

- `<Component>Config` objects for transports, protocols and storage, built from arrays or iterables.
- XOAUTH2 for SMTP, with a token provider, and for POP3 through `Protocol\Pop3\Xoauth2\Microsoft`.
- A `Failover` transport, which tries a list of transports in turn.
- Address groups, read and written as `AddressGroup` values.
- SMTPUTF8 and RFC 6532 UTF-8 headers.
- `Contenir\Mail\Testing\InMemoryConnection`, which scripts a server conversation for protocol tests.
- Documentation of the [security model](docs/book/security.md), [standards conformance](docs/book/standards.md) and how the [laminas-mail issues](docs/book/laminas-issues.md) affect this package.

### Changed

- Value objects have typed constructors; the magic accessors are gone.
- SMTP defaults to port 587 and requires STARTTLS. Peers are verified.
- `Sendmail` runs the program with `proc_open` and no shell, and still supports `mail()`.
- A Message-ID is generated on the sender's domain.
- A message may have only one of each unique header.
- Content-Type parameters stay on one line while they fit.
- mbstring is no longer needed.

### Removed

- Magic property and method access on messages, headers and parts.

### Fixed

- Headers in a legacy charset are read as Windows-1252 instead of making the message unreadable (laminas/laminas-mail#58, #209, #234, #263).
- IMAP quoted strings keep escaped quotes and backslashes (laminas/laminas-mail#62, #188).
- Parentheses inside a quoted display name are kept as text (laminas/laminas-mail#70).
- A malformed header line is dropped when reading, instead of making the message unreadable (laminas/laminas-mail#76, #221).
- A refused POP3 request reports the server's reason (laminas/laminas-mail#187).
- The password is never sent to an IMAP server that advertises LOGINDISABLED (laminas/laminas-mail#258).

### Security

- Header, SMTP command, IMAP command and sendmail argument injection are refused.
- Server responses, header blocks and MIME parts are limited in size and count.
- Encoded words are decoded only from an allow-list of charsets (CVE-2024-2961).
- SMTP transports refuse to be serialised.
