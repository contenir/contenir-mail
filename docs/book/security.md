# Security

A mail library sits on several trust boundaries at once. Application input
becomes header text, protocol commands and shell arguments. Replies from mail
servers and messages from strangers are parsed. Files are written to and read
from mailboxes on disk. Most published vulnerabilities in PHP mail libraries,
including laminas-mail's predecessors, came from one of these boundaries
trusting what crossed it.

contenir-mail checks input where it enters, refuses what it cannot represent
safely rather than cleaning it up, and secures connections by default. Every
protection below has a regression test, named in the tables, and the suite runs
under mutation testing at 100% covered-code MSI, so a test that stopped guarding
its protection would fail CI.

This document describes the state of the code reviewed on 8 October 2026
(commit `1ad388a3`). To report a vulnerability, see
[SECURITY.md](../../SECURITY.md).

## Threat model

**Assets:** the host the library runs on (through sendmail), mail server and
mailbox credentials, the mailboxes themselves, and recipients' trust in what a
message says about its sender and content.

**Trust boundaries and the attackers on the far side:**

| Boundary | Attacker | What they control |
| --- | --- | --- |
| Application → composer | A user of the application | Names, addresses, subjects, attachment names, body text |
| Library → sendmail | The same user, through the envelope | The sender and recipient addresses |
| Library → SMTP, IMAP, POP3 server | The same user, through commands | Addresses, folder names, flags, search terms |
| Server → library | A hostile or compromised server, or anyone on the network path | Every reply byte, including before TLS starts |
| Message → parser | Anyone who can send mail to a mailbox the application reads | Every header and body byte |
| Configuration → library | Whoever writes the configuration | Hosts, paths, credentials, callables |
| Filesystem → storage | Other local users | Links, permissions and files in mailbox directories |

Configuration is trusted: an attacker who controls it can already point mail at
their own server. Everything else is not.

## Protections by component

### Addresses and headers

| Protection | Evidence |
| --- | --- |
| CR, LF and other control characters are refused in every part of an address, including quoted local parts (the class of CVE-2026-45067 in Symfony and CVE-2015-8476 in PHPMailer) | `AddressTest::rejectsCrlfInAnyPart`, `rejectsControlCharacters` |
| Bidirectional overrides are refused in addresses, and in display names except for the left-to-right and right-to-left marks | `AddressTest::rejectsBidirectionalOverrides` |
| Internationalised domains are converted with the IDNA2008 bidi and CONTEXTJ checks, which reject look-alike labels | `EmailAddressValidatorTest` (`zero-width joiner in host`, `mixed-direction host label`) |
| Header values refuse CR, LF outside folding, NUL, DEL and C1 controls (ZF2015-04, CVE-2015-3154) | `HeadersTest::fromIterableRejectsInjectedLineBreaks`, `GenericHeaderTest::fromStringRaisesExceptionWhenCrlfInjectionIsDetected` |
| Display names containing specials are quoted, and non-ASCII names are encoded with those specials escaped, so a name can never add recipients | `AddressListDisplayNameTest`, `quotesAndEscapesDisplayNameContainingSpecials`, `encodedDisplayNameHoldsNoRawDoubleQuote` |
| Encoded words are decoded only in display names and unstructured text, never in an address (Heyes, "Splitting the email atom", 2024) | `encoded @ in display name` stays a display name; an encoded word in an addr-spec is kept literally |
| Header names longer than 997 characters are refused, so no written line exceeds 998 octets | `HeaderNameTest::rejectsBuiltNameLongerThanLimit`, `writesNoLineLongerThan998WithNameOfMaximumLength` |
| Every transport checks each header for a line break that is not folding before writing it | `SmtpTest`, `SendmailTest`, `FileTest`: `refusesHeaderWithUnfoldedLineBreakAgainstHeaderInjection` |

### MIME composing

| Protection | Evidence |
| --- | --- |
| Quoted parameter values escape `"` and `\` and refuse CR, LF and NUL, so a filename cannot add a second parameter (PHPMailer CVE-2020-13625) | `escapesQuoteAndBackslashInParameterValue`, `escapesQuoteInCharset`, `rejectsParameterValueWithControlCharacter` |
| Parameter names, media types and disposition types must be RFC 2045 tokens (Symfony CVE-2026-45070) | `ContentTypeTest`, `ContentDispositionTest` token cases |
| Non-ASCII parameter values are written with RFC 2231, not as encoded words inside quotes | `foldsParametersAtTheLineLimit`, `readsBackEscapedParameterValue` |
| Multipart boundaries are validated against RFC 2046 and a generated boundary cannot occur in base64 or quoted-printable content | `MultipartTest` boundary cases |
| Attachment paths must be local files; `phar://`, `http://` and other stream wrappers are refused (PHPMailer CVE-2018-19296, CVE-2020-36326) | `AttachmentTest`, `FileConfigTest::rejectsStreamWrapperPath` |

### MIME and message parsing

| Protection | Evidence |
| --- | --- |
| Structured headers are parsed before encoded words are decoded, so decoded text cannot change the structure (Mailsploit, 2017) | `ContentDispositionTest`, `keepsInjectedSubjectAsOneDecodedValue` |
| Header blocks are limited to 1,000 headers and 1 MiB | `readsHeaderBlockOfExactlyTheLimit`, `refusesHeaderBlockLargerThanTheLimit` |
| Multiparts are limited to 1,000 parts and 32 levels of nesting, and a missing closing boundary cannot loop | `refusesPartsNestedTooDeeply`, `refusesMultipartWithoutClosingBoundary`, `hasNoPartsWhenBoundaryNeverAppears` |
| A header its class cannot parse is kept as a `GenericHeader` instead of making the message unreadable | `MessageTest::keepsMalformedHeaderAsGenericHeaderAndParsesTheRest` |
| Invalid UTF-8 in decoded text is replaced with U+FFFD | `Utf8Test`, `SafeTextTest` |
| Attachment filenames read from mail are available sanitised: path components, controls and bidi characters removed | `SafeTextTest`, `Storage\Part::getSafeFilename()` |

### SMTP

| Protection | Evidence |
| --- | --- |
| Every command line refuses CR, LF and NUL (PHPMailer CVE-2015-8476) | `SmtpTransactionTest::refusesLineBreakInCommandAgainstInjection` |
| Envelope addresses refuse controls, `<`, `>` and unquoted spaces | `refusesUnsafeSenderAgainstCommandInjection`, `refusesUnsafeRecipientAgainstCommandInjection`, `EnvelopeTest::rejectsUnsafeSender` |
| Bare CR and LF in the body become CRLF before dot-stuffing, so content can never end DATA early (SMTP smuggling, CVE-2023-51764/5/6) | `sendsNoEarlyEndOfDataAgainstSmtpSmuggling`, `doublesLeadingDot` |
| A body line over 998 octets is refused, never rewritten | `refusesLineLongerThanLimitRatherThanAlteringIt` |
| STARTTLS is required by default and must be advertised; a refused or failed upgrade ends the session | `refusesServerThatDoesNotOfferStartTls`, `connectsWithStartTlsByDefault` |
| Bytes a server sends before the TLS handshake are refused (CVE-2011-0411, "NO STARTTLS", 2021) | `SmtpSocketTest::refusesTlsWhenServerSentDataAfterAgreeing` |
| Capabilities heard before TLS are discarded | `discardsCapabilitiesFromBeforeStartTls` |
| The server certificate is verified, with TLS 1.2 or later only (RFC 8996) | `verifiesServerCertificateByDefault`, `offersOnlyTls12And13` |
| AUTH is refused over an unencrypted connection unless `allow_insecure_auth` is set, and only advertised mechanisms are used | `refusesToAuthenticateOverUnencryptedConnection`, `refusesMechanismServerDoesNotOffer` |
| Credentials are kept out of the session log, the last request and `var_dump()` output | `keepsCredentialsOutOfSessionLog`, `keepsCredentialsOutOfLastRequest`, `keepsPasswordOutOfDumps`, `keepsTokenOutOfDumps` |
| SASL fields refuse values that could rewrite them | `XOAuth2Test::rejectsValuesThatCouldRewriteSaslFields` |
| Replies are capped at 100 lines, and malformed replies are refused | `refusesReplyLongerThanLimit`, `refusesMalformedReply` |
| SIZE and SMTPUTF8 are honoured: an oversized message or a non-ASCII address the server cannot take is refused before sending | `refusesMessageLargerThanServerAccepts`, `refusesInternationalSenderWithoutSmtpUtf8` |

### Sendmail

The `-f` argument injection that hit PHPMailer (CVE-2016-10033, CVE-2016-10045),
Zend Mail (ZF2016-04, CVE-2016-10034), SwiftMailer (CVE-2016-10074) and
SquirrelMail (CVE-2017-7692) came from passing escaped addresses to `mail()`,
which escapes them again for a shell.

| Protection | Evidence |
| --- | --- |
| With a configured `path`, sendmail runs through `proc_open()` with an argument list and no shell | `passesQuotedSenderAsOneArgument` |
| Recipients follow `--`, so a recipient starting with `-` cannot be read as an option (Symfony CVE-2026-45068) | `passesRecipientStartingWithDashAfterSeparator` |
| Through `mail()`, `-f` is only passed for a shell-safe sender; any other sender throws | `SendmailTest::refusesSenderUnsafeForCommandLine` |
| Each configured parameter must be shell-safe | `SendmailConfigTest::rejectsParameterUnsafeForShell` |

### File transport

| Protection | Evidence |
| --- | --- |
| Files are created exclusively with mode 0600 and names that include random bytes | `refusesToOverwriteExistingFile`, `writesFileReadableOnlyByOwner`, `namesFilesWithTimeAndRandomPart` |
| A planted symlink is not written through, and a symlinked or non-local target directory is refused | `refusesToWriteThroughPlantedSymlink`, `rejectsSymlinkedDirectory`, `rejectsStreamWrapperPath` |
| A callback's file name must be a plain file name | `rejectsNameThatIsNotPlainFileName` |

### IMAP and POP3

| Protection | Evidence |
| --- | --- |
| Sequence sets, flags and message numbers are validated; strings that a quoted string cannot carry are sent as literals (Roundcube CVE-2026-35538) | `CommandInjectionTest` (IMAP and POP3), `sendsStringsAQuotedStringCannotCarryAsLiterals`, `escapesQuotesAndBackslashesInAFolderName` |
| Credentials refuse values that would end the command | `refusesCredentialsThatWouldEndTheCommand`, `refusesCredentialsThatWouldEndTheApopCommand`, `refusesSaslFieldInjectionThroughControlCharacters` |
| STARTTLS and STLS are required by default; refusal, absence or failure stops the session | `refusesToContinueInPlainTextWhenStartTlsIsNotOffered`, `…WhenStlsIsNotOffered`, `…WhenCapaIsNotSupported`, `…WhenTheServerRefusesStartTls` |
| Bytes buffered before the TLS handshake are refused | `refusesResponsesInjectedBeforeTheTlsHandshake`, `refusesTlsWhenPlainTextBytesAreBuffered` |
| Untrusted certificates are refused, with or without STARTTLS | `refusesAnUntrustedCertificateByDefault`, `refusesAnUntrustedCertificateAfterStartTls` |
| Line and response sizes are limited (8 MiB and 64 MiB by default), and a literal larger than the limit is refused before it is read | `ResponseDecodingTest` limit cases |
| A response with repeated spaces cannot loop | `skipsEmptyTokensBetweenRepeatedSpaces` |
| Credentials are redacted in the log | `keepsCredentialsOutOfTheLog`, `logsASensitiveRequestAsItsRedactedForm` |

### Storage

| Protection | Evidence |
| --- | --- |
| Paths must be local; stream wrappers are refused | `refusesPathThatIsNotLocal`, `refusesDirnameThatIsNotLocal` |
| Folder names cannot leave the mailbox tree | `refusesFolderOutsideTheTree`, `refusesFolderNameWithLineBreak` |
| Symlinked folders and files are refused | `refusesToInitialiseOverLink`, `refusesToRemoveLinkedFolderDirectory` |
| Maildir deliveries are created exclusively with mode 0600, synced, then linked | `refusesToDeliverOverExistingFile`, `hasPrivateModesByDefault`, `createsFilesWithConfiguredMode` |
| Unique names escape the host name | `escapesHostInUniqueName` |
| Message numbers must be positive integers | `refusesMessageNumberBelowOne` |

### Objects and serialization

Protocol and storage objects refuse to be unserialized, so a crafted payload
never reaches a destructor that talks to a server (the gadget class of
CVE-2021-3007 and CVE-2024-28859): `refusesToUnserializeSoACraftedPayloadNeverReachesTheDestructor`,
`cannotBeUnserialized`. A protocol that never connected sends nothing when it is
destroyed: `sendsNothingWhenDestroyedWithoutHavingConnected`.

## Vulnerability history

Published advisories for PHP mail libraries, and whether this code is exposed.
laminas-mail and laminas-mime have no advisories of their own; their history is
Zend Framework's.

| Advisory | Class | Status here |
| --- | --- | --- |
| ZF2015-04 / CVE-2015-3154 (Zend Mail) | CRLF in header values | Not exposed: header values, names and addresses refuse line breaks |
| ZF2016-04 / CVE-2016-10034 (zend-mail) | Sendmail `-f` argument injection | Not exposed: shell-safe senders only through `mail()`; no shell with `path` |
| CVE-2016-10033, CVE-2016-10045 (PHPMailer) | The same, and its escaping bypass | Not exposed, as above |
| CVE-2016-10074 (SwiftMailer) | The same through `mail()` | Not exposed, as above |
| CVE-2017-7692 (SquirrelMail) | The same through spaces in `-f` | Not exposed, as above |
| CVE-2015-8476 (PHPMailer) | CRLF in addresses reaching MAIL FROM and RCPT TO | Not exposed: addresses and every command line refuse line breaks |
| CVE-2026-45067 (Symfony Mime) | CRLF in a quoted local part reaching headers and SMTP | Not exposed, as above |
| CVE-2026-45068 (Symfony Mailer) | Sendmail recipient starting with `-` | Not exposed: `--` before recipients |
| CVE-2026-45070 (Symfony Mime) | Header injection through parameter names | Not exposed: names must be tokens |
| CVE-2020-13625 (PHPMailer) | Unescaped quote in attachment names | Not exposed: `"` and `\` escaped |
| CVE-2018-19296, CVE-2020-36326 (PHPMailer) | `phar://` attachment paths | Not exposed: local paths only |
| CVE-2017-5223 (PHPMailer) | Local files attached through relative image paths | Not applicable: nothing is attached implicitly |
| CVE-2021-3603 (PHPMailer) | A string naming a function used as a callable | **Partly exposed**: see open findings |
| CVE-2021-3007 (laminas-http), CVE-2024-28859 (SwiftMailer) | Destructor gadgets reached through `unserialize()` | Protocols and storage refuse unserialize; one transport does not (see open findings) |
| CVE-2023-51764/5/6 (SMTP smuggling) | Bare CR or LF ending DATA early | Not exposed: line endings normalised before dot-stuffing |
| CVE-2011-0411 and "NO STARTTLS" (2021) | Pre-handshake bytes processed after TLS | Not exposed: buffered bytes refused |
| CVE-2026-35538 (Roundcube) | IMAP command injection | Not exposed: validated arguments and literals |
| CVE-2024-2961 (glibc iconv) | Overflow converting from ISO-2022-CN-EXT | **Depends on the system's iconv**: see open findings |
| Mailsploit (2017) | Encoded words decoding to controls or addresses | Not exposed: structure parsed first, controls refused, addr-spec never decoded |
| "Splitting the email atom" (2024) | Encoded words and legacy syntax inside addresses | Not exposed: addr-spec never decoded; obsolete routes are not parsed as addresses |

## Open findings

These were found in this review and are not yet fixed. None is exploitable
without control of the configuration or an unpatched system library.

| # | Severity | Finding | Recommendation |
| --- | --- | --- | --- |
| 1 | Medium | `ConfigReader::callable()` and `stringOrCallable()` accept anything `is_callable()` does, including a string naming a function and a `[class, method]` array. Where configuration is stored somewhere less trusted than code, a setting such as FileConfig's `callback` can name any function or static method, which is then called with library objects. This is the class of PHPMailer CVE-2021-3603. | Accept only `Closure` and invokable objects. |
| 2 | Low (system-dependent) | Encoded-word charsets come from the message and are passed to `iconv()`. On systems with an unpatched glibc (2.39 and earlier), ISO-2022-CN-EXT can overflow (CVE-2024-2961). | Decode only an allow-list of charsets, leaving others as they are. |
| 3 | Low | `Transport\Smtp` can be unserialized, and its destructor then runs on an uninitialised object. It does nothing harmful today, but transports with destructors should refuse unserialize as protocols do. | Throw from `__unserialize()` in `Transport\Smtp`, as the protocol classes do. |
| 4 | Low | `SendmailProcess` waits for sendmail with no time limit, so a hung sendmail blocks the worker. The message is briefly held in a 0600 temporary file. | Add a configurable timeout and terminate the process when it passes. |
| 5 | Low | `Utf8::scrub()` maps a closure over every character: a hostile 1 MiB header value costs about 0.7 s and 31 MB. Header limits bound it. | Replace invalid sequences with one regular expression. |

One functional issue was found alongside these: a `Message` cannot be
serialized, because `Headers` keeps original wire text in a `WeakMap`. laminas-mail
messages could be, and queue workers rely on it. `Headers` should serialize its
headers and their wire text explicitly.

## What was not tested

- No fuzzing campaign has been run; the parsers are covered by unit, boundary and
  mutation tests and by hand-written hostile inputs.
- Behaviour against real servers (Postfix, Exim, Dovecot, Gmail, Microsoft 365) is
  tested only through scripted conversations and a local TLS server.
- S/MIME, OpenPGP and DKIM signing are not implemented, so not assessed.

## Process

- CI runs the suite on PHP 8.3, 8.4 and 8.5, with Mago static analysis and
  Infection, and fails below 100% covered-code MSI.
- Runtime dependencies are the PSR clock and container interfaces and
  `symfony/polyfill-intl-idn`.
