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
(commit `1ad388a3`) and the fixes made after that review. To report a vulnerability, see
[SECURITY.md](../../SECURITY.md).

## Threat model

**Assets:** the host the library runs on (through sendmail), mail server and
mailbox credentials, DKIM private keys, the mailboxes themselves, and recipients' trust in what a
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
| A generated boundary is 128 random bits from `random_bytes()`, so a sender cannot predict it and end a part early in content that is not encoded | `generatesADifferentBoundaryEachTime`, `generatesABoundaryThatCannotAppearInEncodedContent` |
| Attachment paths must be local files; `phar://`, `http://` and other stream wrappers are refused (PHPMailer CVE-2018-19296, CVE-2020-36326) | `AttachmentTest`, `FileConfigTest::rejectsStreamWrapperPath` |

### MIME and message parsing

| Protection | Evidence |
| --- | --- |
| Structured headers are parsed before encoded words are decoded, so decoded text cannot change the structure (Mailsploit, 2017) | `ContentDispositionTest`, `keepsInjectedSubjectAsOneDecodedValue` |
| Header blocks are limited to 1,000 headers and 1 MiB | `readsHeaderBlockOfExactlyTheLimit`, `refusesHeaderBlockLargerThanTheLimit` |
| A folded header is unfolded in time linear in its lines; one folded over 80,000 lines took 17 seconds before 0.3.0 | `HeaderBlockTest::unfoldsALongFoldInLinearTime` |
| Multiparts are limited to 1,000 parts and 32 levels of nesting, and a missing closing boundary cannot loop | `refusesPartsNestedTooDeeply`, `refusesMultipartWithoutClosingBoundary`, `hasNoPartsWhenBoundaryNeverAppears` |
| A header its class cannot parse is kept as a `GenericHeader` instead of making the message unreadable | `MessageTest::keepsMalformedHeaderAsGenericHeaderAndParsesTheRest` |
| Invalid UTF-8 in decoded text is replaced with U+FFFD | `Utf8Test`, `SafeTextTest` |
| Attachment filenames read from mail are available sanitised: path components, controls and bidi characters removed | `SafeTextTest`, `Storage\Part::getSafeFilename()` |
| TNEF (`winmail.dat`) containers are parsed as hostile. Every length is checked against the bytes left before it's used, a record with a bad checksum stops reading, and a container is limited to 100 attachments and 64 MiB of output. Compressed RTF can't grow past its declared size or the output limit, and a reference to bytes never written is refused. File names go through `SafeText::filename()`, and media types other than a plain `type/subtype` become `application/octet-stream`. Random and damaged containers, read with a fixed seed, only ever give a result or a package exception | `ReaderTest::refusesMalformedData`, `refusesOutputPastTheByteLimit`, `CompressedRtfTest::refusesMalformedCompressedRtf`, `MapiPropertiesTest::refusesMalformedProperties`, `ReaderFuzzTest` |

### TLS settings

SMTP, IMAP and POP3 connections share these settings:

- `cafile` and `capath`: certificate authorities to trust, in place of the system's;
- `peer_name`: the name the certificate must carry;
- `allow_self_signed`;
- `local_cert` and `local_pk`: a client certificate;
- `passphrase`: the passphrase of the certificate's private key, when it is encrypted.

They're typed fields of `TlsConfig`. There's no pass-through for other `ssl` context options, so nothing else reaches the stream context.

| Protection | Evidence |
| --- | --- |
| Peer verification and name checking stay on unless `verify_peer` is set to false. No TLS setting changes `verify_peer` or `verify_peer_name`, because they're written after the TLS settings | `TlsConfigTest`, `StreamConnection::open()` |
| `allow_self_signed` is the only setting that weakens verification, and it's off unless set to true. Even then, the certificate's name is still checked | `leavesSelfSignedCertificatesRefusedUnlessAllowed`, `stillChecksThePeerNameOfASelfSignedCertificate` |
| The passphrase is marked `#[SensitiveParameter]`, and `var_dump()` of a `TlsConfig`, or of a config that holds one, shows `TlsConfig::REDACTED` in its place | `redactsThePassphraseInDumps`, `SecretsInTracesTest` |
| Misspelt or unknown setting names are refused, not ignored, so a typo can't silently leave a CA untrusted | `refusesAnInvalidSetting` (misspelt setting) |
| Paths and names that are empty or hold a control character are refused, and so is a private key without its certificate | `refusesAnInvalidSetting` |
| The settings reach the handshake for TLS from the start and for STARTTLS | `acceptsASelfSignedCertificateForTheNamedPeerWhenAllowed`, `trustsTheCertificateAuthorityGiven` |

Prefer `cafile` or `capath` for a private certificate authority, and `peer_name` when connecting by address. Both keep full verification. `allow_self_signed` accepts any certificate that signs itself for the right name, including an attacker's on the network path. Use it only for a test server, or a pinned internal host on a trusted network.

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
| Passwords, tokens, keys and SASL responses never appear in an exception's trace, even with `zend.exception_ignore_args` off: every parameter that can carry one is `#[SensitiveParameter]`, from the settings array to `ConnectionInterface::write()` | `SecretsInTracesTest` (SMTP cases) |
| SASL fields refuse values that could rewrite them | `Sasl\Xoauth2Test::rejectsValuesThatCouldRewriteSaslFields` |
| SCRAM-SHA-256 fails closed: a wrong or missing server signature, a nonce that does not extend the client's, and an iteration count outside 4096 to 1,000,000 are refused, and the exchange cancelled | `Sasl\ScramSha256ExchangeTest`, `Sasl\ScramSha256Test::cancelsTheExchangeWhenAStepIsRefused`, `refusesSuccessWithoutTheServersProof` |
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
| A sendmail program still running after the timeout (60 seconds by default) is stopped, so a hung sendmail cannot block a worker | `stopsProgramThatRunsLongerThanTheTimeout`, `stopsProgramAfterTheConfiguredTimeout` |
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
| A response line is tokenized in one pass, so a long line costs time linear in its length; a SEARCH reply of 200,000 ids took 9 seconds before 0.3.0 | `TokenizerScalingTest::decodesALongLineInLinearTime` |
| A sequence set is checked range by range, so a set of many ranges, sent or received in ESEARCH, never exhausts PCRE's stack | `LargeSequenceSetTest` |
| Credentials are redacted in the log | `keepsCredentialsOutOfTheLog`, `logsASensitiveRequestAsItsRedactedForm` |
| A PSR-3 `logger` never receives a credential: LOGIN and its literals, USER, PASS, APOP and every SASL response, in IMAP, POP3 and SMTP, are written as secrets and logged as `[redacted]` | `LoggedSecretsTest`, `LoggingConnectionTest` |
| Credentials never appear in an exception's trace: LOGIN, PASS, literals and command tokens are `#[SensitiveParameter]` down to the connection, and `ConfigReader` hides its values from `var_dump()` | `SecretsInTracesTest` (IMAP, POP3 and config cases), `ConfigReaderTest::hidesEveryValueFromVarDump` |
| SCRAM-SHA-256 checks the server's signature before finishing, and cancels the exchange with `*` when it does not match. Without channel binding, which PHP cannot provide, it relies on TLS for the connection itself | `AuthenticateScramTest` (IMAP and POP3) |

### Storage

| Protection | Evidence |
| --- | --- |
| Paths must be local; stream wrappers are refused | `refusesPathThatIsNotLocal`, `refusesDirnameThatIsNotLocal` |
| Folder names cannot leave the mailbox tree | `refusesFolderOutsideTheTree`, `refusesFolderNameWithLineBreak` |
| Symlinked folders and files are refused | `refusesToInitializeOverLink`, `refusesToRemoveLinkedFolderDirectory` |
| Maildir deliveries are created exclusively with mode 0600, synced, then linked | `refusesToDeliverOverExistingFile`, `hasPrivateModesByDefault`, `createsFilesWithConfiguredMode` |
| Unique names escape the host name | `escapesHostInUniqueName` |
| Message numbers must be positive integers | `refusesMessageNumberBelowOne` |

### DKIM signing

| Protection | Evidence |
| --- | --- |
| The private key is shown as `[hidden]` by `var_dump()` and `print_r()`, and refuses to be serialized, so it can't reach a log, a queue or a cache | `hidesKeyFromDebugOutput`, `printsNoKeyMaterial`, `cannotBeSerialized` |
| RSA keys under 1024 bits are refused, and `rsa-sha1` can't be chosen (RFC 8301); the docs ask for 2048 bits or more | `refusesRsaKeyUnder1024Bits`, `refusesRsaSha1` |
| An Ed25519 secret key whose public half doesn't match its seed is refused | `refusesEd25519SecretKeyWhosePublicHalfDoesNotMatch` |
| An encrypted key without its passphrase is refused; OpenSSL is never left to prompt for one on the terminal | `refusesPemItCannotRead` (missing passphrase) |
| Key paths must be local files; stream wrappers are refused | `refusesStreamWrapperPath` |
| Domains, selectors, identities and header names refuse white space, `;` and control characters, so a setting can't add a tag or a header | `refusesInvalidDomain`, `refusesInvalidSelector`, `refusesIdentityOutsideTheDomain`, `refusesHeaders` |
| From is always signed, and a message without From is refused | `refusesHeaders` (without From), `refusesMessageWithoutFrom` |
| `l=` is off by default, so content can't be appended to a signed body | `hasSafeDefaults` |
| Headers are checked for unfolded line breaks before they're signed | `refusesHeaderWithLineBreakThatIsNotFolding` |

### Objects and serialization

Protocol and storage objects refuse to be unserialized, so a crafted payload
never reaches a destructor that talks to a server (the gadget class of
CVE-2021-3007 and CVE-2024-28859): `refusesToUnserializeSoACraftedPayloadNeverReachesTheDestructor`,
`cannotBeUnserialized`. A protocol that never connected sends nothing when it is
destroyed: `sendsNothingWhenDestroyedWithoutHavingConnected`. The refusal is
an exception of the package, a `LogicException` from the component's
`Exception` namespace, so `catch (Contenir\Mail\Exception\ExceptionInterface)`
handles it like any other.

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
| CVE-2021-3603 (PHPMailer) | A string naming a function used as a callable | Not exposed: settings accept only a Closure or an invokable object (`refusesCallableGivenByNameAgainstFunctionInjection`) |
| CVE-2021-3007 (laminas-http), CVE-2024-28859 (SwiftMailer) | Destructor gadgets reached through `unserialize()` | Not exposed: protocols, storage and the SMTP transport refuse unserialize |
| CVE-2023-51764/5/6 (SMTP smuggling) | Bare CR or LF ending DATA early | Not exposed: line endings normalised before dot-stuffing |
| CVE-2011-0411 and "NO STARTTLS" (2021) | Pre-handshake bytes processed after TLS | Not exposed: buffered bytes refused |
| CVE-2026-35538 (Roundcube) | IMAP command injection | Not exposed: validated arguments and literals |
| CVE-2024-2961 (glibc iconv) | Overflow converting from ISO-2022-CN-EXT | Not exposed: only charsets mail is written in reach iconv (`leavesTextInOtherCharsetsAsItIsAgainstConverterAbuse`) |
| Mailsploit (2017) | Encoded words decoding to controls or addresses | Not exposed: structure parsed first, controls refused, addr-spec never decoded |
| "Splitting the email atom" (2024) | Encoded words and legacy syntax inside addresses | Not exposed: addr-spec never decoded; obsolete routes are not parsed as addresses |

## Findings from the review

The review of 8 October 2026 found five issues and one regression. All are
fixed, each with a regression test.

| # | Severity | Finding | Fix | Evidence |
| --- | --- | --- | --- | --- |
| 1 | Medium | Callable settings accepted a function name or `[class, method]` array, so stored settings could name any function to call (the class of PHPMailer's CVE-2021-3603). | Only a `Closure` or an invokable object is accepted. | `refusesCallableGivenByNameAgainstFunctionInjection` |
| 2 | Low | Charsets named by a message reached `iconv()`, including glibc's ISO-2022-CN-EXT (CVE-2024-2961). | Only charsets mail is written in are converted. | `leavesTextInOtherCharsetsAsItIsAgainstConverterAbuse` |
| 3 | Low | `Transport\Smtp` could be unserialized, reaching its destructor. | It refuses serialize and unserialize, as the protocols do. | `refusesToUnserializeSoACraftedPayloadNeverReachesTheDestructor` |
| 4 | Low | A hung sendmail program blocked the worker forever. | It is stopped after a timeout, 60 seconds by default. | `stopsProgramThatRunsLongerThanTheTimeout` |
| 5 | Low | Scrubbing invalid UTF-8 cost about 0.7 s per hostile MiB. | Scrubbed by run in slices, falling back by character at PCRE's limits; 1 MiB now takes milliseconds. | `Utf8ScrubTest` |
| – | Regression | A `Message` could not be serialized for queues. | Headers and parts serialize; original header text is kept only when it is one well-formed line for its header. | `HeadersSerializationTest`, `MessageSerializationTest` |

There are no known open findings.

## What was not tested

- No fuzzing campaign has been run; the parsers are covered by unit, boundary and
  mutation tests and by hand-written hostile inputs.
- Behaviour against real servers (Postfix, Exim, Dovecot, Gmail, Microsoft 365) is
  tested only through scripted conversations and a local TLS server.
- S/MIME and OpenPGP are not implemented, so not assessed. DKIM signatures are
  checked against the RFC 8463 examples and a test verifier, not against
  receiving servers.

## Process

- CI runs the suite on PHP 8.3, 8.4 and 8.5, with Mago static analysis and
  Infection, and fails below 100% covered-code MSI.
- Runtime dependencies are the PSR clock and container interfaces and
  `symfony/polyfill-intl-idn`.
