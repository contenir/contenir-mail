# Naming conventions

The public API is named by these rules, so a method's name tells you what it
does before you read its documentation. Names inherited from laminas-mail
keep their spelling for compatibility, even where they break a rule; new API
follows them.

## Methods

Prefix | Meaning | Examples
--- | --- | ---
`get*` | Reads from a storage or an object, without changing it | `getFolderStatus()`, `getSortedNumbers()`
`has*` | Whether something is possessed or offered | `hasCapability()`, `hasFlag()`
`is*Enabled`, `uses*` | Whether a state is on | `isImap4Rev2Enabled()`, `usesUtf8MailboxNames()`
`prefer*`, `set*` | Changes the object it is called on | `preferImap4Rev2()`, `setTo()`
`with*`, `without*` | Returns a changed copy of an immutable object, which stays as it was. These carry `#[\NoDiscard]` | `Headers::with()`, `AddressList::without()`
`from*` | A named constructor | `PrivateKey::fromEd25519()`, `ConnectionConfig::fromIterable()`

Protocol methods are named after the IMAP, POP3 or SMTP command they send,
such as `Protocol\Imap::status()`, `sort()` and `move()`. A variant that
reads more from the reply says so: `appendReturningUids()`.

## Booleans

A boolean reads as a predicate, never as a bare noun: `includeTimestamp`,
`signBodyLength`, `allowSelfSigned`, not `timestamp` or `bodyLength`.

A mode chosen once, when an object is built, is a boolean constructor
argument passed by name, so the call site reads on its own:
`new Address($email, strict: false)`. The object can say which mode it has,
as `Address::isStrict()` does.

## Mail words

- **Folder** in the public API; **mailbox** only where the protocol is
  described, as RFC 3501 names it.
- **Number**: a message's sequence number in the selected folder, which
  changes as messages are expunged.
- **UID**: an IMAP UID, an `int`, meaningful only together with the folder's
  UIDVALIDITY (`FolderStatus::$uidValidity`).
- **Unique ID**: the storage-neutral `string` that `getUniqueId()` returns.

## Spelling

- Acronyms are StudlyCase: `Imap4Rev2`, `TlsConfig`, `Utf8`, `Uid`.
- Limits are `MAX_*` and `MIN_*`: `MAX_LITERAL_MINUS_SIZE`, `MAX_UIDS`.
- What is shown in place of a secret is `REDACTED`.
- Identifiers use American spelling; prose may use British.
- Settings arrays keep the spelling of what they mirror: TLS settings are
  read as `cafile`, `capath` and `local_pk`, PHP's ssl context options, while
  `TlsConfig` names them `caFile`, `caPath` and `localPrivateKey`.

## Errors

New methods throw when the server refuses, as
`Protocol\Exception\CommandRefusedException`, rather than returning false.
A return type of `X|bool` where `true` means "no data" is avoided: a method
that may have nothing to return returns `?X`, as `appendReturningUids()`
does. Methods kept from laminas-mail still return a bool.

## Classes

An internal class never shares its short name with a public one, so an
import never has to be aliased: `Sasl\ScramSha256Exchange` runs the exchange
for the public `Sasl\ScramSha256`. Two names kept for compatibility are the
exceptions: the deprecated `Smtp\Auth\XOAuth2`, the 0.2 name of
`Sasl\Xoauth2`, and the internal XOAUTH2 encoder `Xoauth2\Xoauth2`, kept
from laminas-mail.

Interfaces end in `Interface` and are named for what they are within their
namespace, without repeating it: `Sasl\MechanismInterface`,
`Sasl\ExchangeInterface`, `Smtp\Auth\AuthenticatorInterface`.

## Authentication

SASL mechanisms live in `Protocol\Sasl` and are named for the mechanism, its
acronyms StudlyCase: `Sasl\Xoauth2` for XOAUTH2, `Sasl\ScramSha256` for
SCRAM-SHA-256. Each implements `Sasl\MechanismInterface`, so IMAP, POP3 and
SMTP take it alike. The `type` that names one in settings is its IANA name in
lower case: `xoauth2`, `scram-sha-256`, `cram-md5`.
