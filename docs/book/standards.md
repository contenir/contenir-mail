# Standards

This page lists the standards contenir-mail implements and how closely it
follows each one. Verdicts were checked against the code reviewed on 8 October
2026 (commit `1ad388a3`, with the fixes made after it). Behaviour was checked with tests where they exist and with
probe scripts where they do not; rows without a test say so.

**Verdicts:**

- **Conforms**: the requirement is met and tested.
- **Partial**: met in the common case, with a gap described in the notes.
- **Deviates**: intentionally different, for the reason given.
- **Not implemented**: an optional feature the package does not offer.

Summary: of the 93 requirements below, 79 conform, 4 are
partial, 2 deviate by design, and 8 optional extensions are not
implemented. The gaps to close are listed at the end.

## Message format: RFC 5322 and RFC 6854

| Requirement | Verdict | Evidence and notes |
| --- | --- | --- |
| §2.1.1 Lines at most 998 octets (MUST) | Conforms | Headers fold or fall back to encoded words; names over 997 characters are refused; SMTP refuses longer body lines. `writesNoLineLongerThan998WithNameOfMaximumLength`, `refusesLineLongerThanLimitRatherThanAlteringIt` |
| §2.1.1 Lines at most 78 characters (SHOULD) | Conforms | Headers fold at 78; quoted-printable and base64 bodies wrap at 76 and 72. Probed: a 1,200-character subject and 500 mixed non-ASCII characters both stay within 78 |
| §2.2 Field syntax, §2.2.3 folding and unfolding | Conforms | `HeaderBlock`, `HeaderWrap`; tab and space folds both unfold. `parsesHeaderBlock` |
| §3.2 Quoted strings, quoted pairs and comments | Conforms | Display names quoted and escaped; comments read after addresses. `quotesAndEscapesDisplayNameContainingSpecials`, `AddressListDisplayNameTest` |
| §3.3 Date and time, including obsolete forms on reading | Conforms | Written as `Wed, 07 Oct 2026 22:42:23 +0000` from a PSR-20 clock; two-digit years, named zones and trailing comments are read. `takesDefaultDateFromClock` |
| §3.4 Addresses and groups | Conforms | Groups are read with their names and written, in order with the other addresses; empty groups such as `undisclosed-recipients:;` too. `readsGroupNamesAndMembers`, `writesGroups` |
| §3.6 Field counts: one Date, one From, at most one Subject, Sender, Reply-To, To, Cc, Bcc, Message-ID (MUST) | Conforms | `addHeader()` refuses a second unique header; `set*()` replace. Reading keeps duplicates, which §4 permits. `refusesSecondUniqueHeader` |
| §3.6.1 Date and From required | Partial | `Date` is added by default; `isValid()` requires From. Nothing stops a message without From being sent. |
| §3.6.2 Sender required when there are several From addresses (MUST) | Conforms | The first From address is written as Sender when none is set. `namesFirstAuthorAsSenderWhenThereAreSeveral` |
| §3.6.4 Message-ID (SHOULD) | Conforms | A new message gets one on its sender's domain; parsed messages keep what they had. `generatesMessageIdOnTheSendersDomain` |
| §4 Obsolete syntax accepted on reading | Conforms | Folding, dates, zones and quoted pairs are read, and an obsolete source route (`<@relay:user@host>`) is dropped from its address. `readsAddressWithoutItsSourceRoute` |
| RFC 6854 Groups in From and Sender | Conforms | From accepts and keeps groups like the other address lists; Sender holds a single mailbox, as RFC 6854 leaves it. `writesGroupGivenToBuilder` |

## MIME: RFC 2045 to RFC 2049, RFC 2183, RFC 2231, RFC 2387, RFC 2392

| Requirement | Verdict | Evidence and notes |
| --- | --- | --- |
| RFC 2045 §4 MIME-Version | Conforms | Added for every MIME body. `singlePartMimeBodySetsMimeVersion`, `multipartMimeBodySetsMimeVersion` |
| RFC 2045 §5 Content-Type grammar: tokens and quoted parameters | Conforms | Types and parameter names must be tokens; quoted values escape `"` and `\`. `escapesQuoteAndBackslashInParameterValue` |
| RFC 2045 §6 Transfer encodings: 7bit, 8bit, binary, quoted-printable, base64 | Conforms | `TransferEncoding` enum; `MimeTest` encoding cases |
| RFC 2045 §6.7 Quoted-printable: lines at most 76, hard line breaks for text, trailing white space encoded | Conforms | Probed: longest line 75. `encodesTextLineBreaksAsHardLineBreaks`, `keepsTrailingSpaceAtEndOfQuotedPrintable`, `doesNotBreakAQuotedPrintableOctetAcrossLines` |
| RFC 2045 §6.8 Base64 lines at most 76 | Conforms | 72-character lines. `PartTest` stream and string cases |
| RFC 2046 §5.1.1 Boundary grammar, 1 to 70 characters | Conforms | `MultipartTest`, `acceptsBoundaryOfTheLengthLimit`, `refusesEmptyBoundary` |
| RFC 2046 §5.1.1 Boundary must not occur in any part (MUST) | Conforms | A part with a line starting with the boundary is refused. `refusesPartContainingItsBoundary` |
| RFC 2046 §5.1.1 Preamble and epilogue | Conforms | A preamble for non-MIME readers is written; epilogues are read and ignored. |
| RFC 2046 §5.1 Nesting | Conforms | Mixed, alternative and related nest to any depth when writing; reading limits depth to 32. `refusesPartsNestedTooDeeply` |
| RFC 2047 §2 Encoded words at most 75 characters | Conforms | Probed: longest 72. `keepsQuotedPrintableHeaderLinesWithinTheLength` |
| RFC 2047 §5 Whole characters in each encoded word | Conforms | `HeaderWrapTest::splitsLongValueOnlyBetweenCharacters`, `keepsEveryEncodedWordWithinSeventyFiveCharacters` |
| RFC 2047 §5 Encoded words only in text and phrases, never in addresses or quoted strings | Conforms | Address specs are never decoded; non-ASCII parameters use RFC 2231. |
| RFC 2047 Decoding: split multi-byte characters, any charset case | Conforms | Probed: `=C3` and `=A9` in adjacent words decode to `é`. |
| RFC 2183 Content-Disposition: inline and attachment, filename | Conforms | `Disposition` enum; `ContentDispositionTest` |
| RFC 2231 Continuations, charset and language, reading and writing | Conforms | Probed: a 45-character non-ASCII filename is written as `filename*0*=UTF-8''…` across five sections and read back. `readsBackEscapedParameterValue`, `foldsParametersAtTheLineLimit` |
| RFC 2387 `type` parameter on multipart/related (MUST) | Conforms | `namesTypeOfRelatedRootPart` |
| RFC 2392 Content-ID in angle brackets for `cid:` | Conforms | `Attachment::inline()`; `BodyTest` |
| RFC 6838 Media type and parameter names at most 127 characters | Conforms | Refused when longer. |

## Internationalisation: RFC 6530 to RFC 6532, IDNA

| Requirement | Verdict | Evidence and notes |
| --- | --- | --- |
| RFC 6532 Raw UTF-8 header values on reading | Conforms | Read into their typed classes; invalid UTF-8 and control characters refused. Probed: `Subject: Grüße` |
| RFC 6532 Raw UTF-8 header values on writing | Deviates | Non-ASCII is always written as RFC 2047 encoded words, which every server accepts, even when the session has SMTPUTF8. |
| RFC 6531 SMTPUTF8 for non-ASCII addresses | Conforms | Declared when needed, refused when the server lacks it. `declaresSmtpUtf8ForInternationalRecipient`, `refusesInternationalSenderWithoutSmtpUtf8` |
| RFC 5890 to 5893, UTS #46 Domain names | Conforms | Non-transitional processing with the bidi and CONTEXTJ checks. `EmailAddressValidatorTest` |

## SMTP: RFC 5321 and extensions

| Requirement | Verdict | Evidence and notes |
| --- | --- | --- |
| RFC 5321 §4.1.1.1 EHLO, falling back to HELO | Conforms | `readsCapabilitiesFromEhlo`, `fallsBackToHeloWhenEhloIsRefused`, `refusesInvalidHeloName` |
| RFC 5321 §4.2 Reply parsing, multiline replies | Conforms | Strict parsing; at most 100 lines. `readsEveryLineOfMultilineReply`, `refusesMalformedReply`, `refusesReplyLongerThanLimit` |
| RFC 5321 §4.1.2 MAIL and RCPT path syntax | Conforms | Controls, angle brackets and unquoted spaces refused. `refusesUnsafeSenderAgainstCommandInjection` |
| RFC 5321 §4.5.2 Dot-stuffing | Conforms | `doublesLeadingDot` |
| RFC 5321 §2.3.8 Lines end with CRLF; no bare CR or LF | Conforms | Normalised before dot-stuffing. `normalisesLineEndingsToCrlf`, `sendsNoEarlyEndOfDataAgainstSmtpSmuggling` |
| RFC 5321 §4.5.3.1.6 Text lines at most 1000 octets including CRLF | Conforms | Longer lines refused, never rewritten. `refusesLineLongerThanLimitRatherThanAlteringIt` |
| RFC 5321 §4.5.3.1 Local part at most 64, domain at most 255 octets | Conforms | `EmailAddressValidatorTest` length cases |
| RFC 3207 STARTTLS, with capabilities discarded after it | Conforms | Required by default. `refusesServerThatDoesNotOfferStartTls`, `discardsCapabilitiesFromBeforeStartTls` |
| RFC 4954 AUTH with an advertised mechanism | Conforms | Refused over plain text. `refusesMechanismServerDoesNotOffer`, `refusesToAuthenticateOverUnencryptedConnection` |
| RFC 4616 PLAIN, LOGIN, RFC 2195 CRAM-MD5, Google XOAUTH2 | Conforms | `Protocol\Smtp\Auth` tests. CRAM-MD5 is kept for compatibility; the docs advise against it. |
| RFC 5802 and RFC 7677 SCRAM-SHA-256 | Conforms | Tested against the RFC 7677 vector. The server's signature is verified; iteration counts outside 4096 to 1,000,000 are refused. `Protocol\Sasl\ScramSha256Test`, `Smtp\Auth\ScramSha256Test`, `authenticatesWithScramSha256`, and against Postfix and Dovecot in `PostfixTest` |
| RFC 5802 SCRAM-SHA-256-PLUS channel binding | Not implemented | PHP exposes neither `tls-unique` nor `tls-exporter`. The gs2 header is `n,,`. |
| RFC 4013 SASLprep | Partial | Printable ASCII is unchanged; other text is normalised to NFKC with intl, and refused without it. The prohibited-character and bidirectional tables are not applied. `SaslPrepTest` |
| RFC 6409 Submission on port 587 | Conforms | The default with STARTTLS. `choosesSeparateStartTlsPortWhenGiven` |
| RFC 8314 Implicit TLS on port 465 | Conforms | `Security::Tls`. |
| RFC 8314 §3 Prefer implicit TLS for submission (SHOULD) | Deviates | STARTTLS on 587 is the default because it is the most widely deployed; it is required, never opportunistic, and implicit TLS is one setting away. |
| RFC 1870 SIZE | Conforms | Declared, and an oversized message refused before sending. `declaresMessageSize`, `refusesMessageLargerThanServerAccepts` |
| RFC 6152 8BITMIME | Conforms | `BODY=8BITMIME` is declared when the server offers it, and 8-bit content is refused when it does not. `declaresEightBitBody`, `refusesEightBitBodyWithoutEightBitMime` |
| RFC 2920 PIPELINING | Conforms | When offered, MAIL and every RCPT go together and the replies are read after. DATA stays a step of its own. Every reply is read before a refusal is reported, then the transaction is reset. `SmtpPipeliningTest`, and against Postfix in `PostfixTest` |
| RFC 3030 CHUNKING and BINARYMIME | Not implemented | |
| RFC 3461 Delivery status notifications | Not implemented | |
| RFC 8689 REQUIRETLS | Not implemented | |
| RFC 7628 OAUTHBEARER | Not implemented | XOAUTH2 covers Google and Microsoft. |

## Signing: RFC 6376, RFC 8463, RFC 8301

| Requirement | Verdict | Evidence and notes |
| --- | --- | --- |
| RFC 6376 §3.4 Simple and relaxed canonicalisation, headers and body | Conforms | Both, for headers and body; relaxed by default. The example of §3.4.6. `canonicalisesHeaderField`, `canonicalisesBody` |
| RFC 6376 §3.5, §3.7 Signature tags; b= computed over the header with b= empty | Conforms | Tags in a fixed order, folded at 78 characters. The published signatures of RFC 8463 are reproduced byte for byte from their keys. `reproducesThePublishedSignatureFromItsKey`, `verifiesThePublishedSignature` |
| RFC 6376 §5.3 Body signed as sent | Conforms | The signed message holds the CRLF body the transports write; SMTP dot-stuffing is undone by the receiver. `sendsOverSmtpABodyThatMatchesTheBodyHash` |
| RFC 6376 §5.4 From signed (MUST) | Conforms | A header list without From and a message without From are refused. `refusesHeaders`, `refusesMessageWithoutFrom` |
| RFC 6376 §5.4.2 Several instances of a header signed from the bottom up | Conforms | `signsEveryInstanceOfAListedHeaderFromTheBottomUp` |
| RFC 8463 Ed25519-SHA256 | Conforms | `reproducesThePublishedSignatureFromItsKey` (Ed25519-SHA256) |
| RFC 8301 No rsa-sha1; RSA keys of at least 1024 bits (MUST) | Conforms | `refusesRsaSha1`, `refusesRsaKeyUnder1024Bits`; the docs ask for 2048 bits. |
| RFC 6376 §6 Verifying signatures | Not implemented | Signing only; incoming mail is verified by the receiving MTA. |

## IMAP: RFC 3501

The client implements IMAP4rev1 (RFC 3501), and turns on IMAP4rev2 (RFC 9051)
after signing in when the server offers it. IMAP4rev1 servers remain the norm,
and rev2 servers accept rev1 clients.

| Requirement | Verdict | Evidence and notes |
| --- | --- | --- |
| §4.3 Literals for strings a quoted string cannot carry | Conforms | `sendsStringsAQuotedStringCannotCarryAsLiterals`, `appendsAMessageAsALiteral` |
| RFC 7888 LITERAL+ and LITERAL- | Conforms | A literal is sent without waiting for the server's `+` when LITERAL+ is offered, or LITERAL- or IMAP4rev2 and it is at most 4096 bytes; otherwise the client waits. Only capabilities already read are consulted. `NonSynchronisingLiteralTest`, and against Dovecot in `ImapTest` |
| §9 Sequence sets, flags and atoms | Conforms | Validated before sending. `CommandInjectionTest` |
| §6.2.1 STARTTLS, with capabilities re-read | Conforms | Required by default. `refusesToContinueInPlainTextWhenStartTlsIsNotOffered` |
| §6.2.3 LOGIN refused when `LOGINDISABLED` is advertised | Conforms | Capabilities are read before LOGIN, and again after STARTTLS; no password is sent when LOGIN is disabled. `refusesToSendPasswordWhenLoginIsDisabled` |
| Response parsing, server literals by byte count | Conforms | `ResponseDecodingTest` |
| §6.2.2 AUTHENTICATE with XOAUTH2, RFC 4959 SASL-IR | Conforms | The token goes with the command when SASL-IR is offered, and after the continuation otherwise. A refused token is answered with an empty response (RFC 7628). `AuthenticateTest`, and against Dovecot in `ImapXoauth2Test` |
| §6.2.2 AUTHENTICATE with SCRAM-SHA-256 | Conforms | Client-first goes with the command when SASL-IR is offered. Server-final is checked before the empty response that ends the exchange; a refused step is cancelled with `*`. `AuthenticateScramTest`, and against Dovecot in `ImapScramTest` |
| §5.1.3 Mailbox names in modified UTF-7 | Conforms | Names are encoded on the way out and decoded from LIST. A malformed run is kept as the server wrote it. `MailboxNameTest`, `writesNamesInModifiedUtf7WithoutUtf8Mailboxes`, and against Dovecot in `ImapMailboxNameTest` |
| RFC 5161 ENABLE, RFC 9051 IMAP4rev2, RFC 6855 UTF8=ACCEPT | Partial | After signing in, IMAP4rev2 is enabled when it's offered, or else UTF8=ACCEPT. Names then travel as UTF-8. SEARCH reads ESEARCH results, bounded to `MAX_SEARCH_RESULTS`, and `\NonExistent` folders can't be selected. IDLE, NAMESPACE, SPECIAL-USE and STATUS SIZE aren't used yet (#52). `Imap4rev2Test` |
| RFC 5256 SORT, RFC 5957 display sort, RFC 4731 ESEARCH counts | Conforms | `Storage\Imap::sortMessages()` sorts on the server, and `getMessages()` fetches a page in one FETCH. Sort keys are checked before sending. `countMessages()` counts with `SEARCH RETURN (COUNT)` when ESEARCH or IMAP4rev2 is on, and from the numbers otherwise. `SortAndCountTest`, `ImapPagingTest` |
| RFC 6851 MOVE | Conforms | `Storage\Imap::moveMessage()` uses MOVE when it's offered, and copy then expunge otherwise. `movesMessagesWhenTheServerOffersMove` |
| RFC 4315 UIDPLUS | Conforms | `appendMessage()` and `copyMessage()` return the UID from the APPENDUID or COPYUID response code, and null without one. UID sets are validated, 32-bit and bounded to `UidPlus::MAX_UIDS`; a malformed code is ignored. The COPYUID that MOVE sends untagged is not read. `UidPlusTest`, `UidPlusCommandTest`, and against Dovecot in `ImapTest` |
| RFC 3691 UNSELECT | Conforms | `Protocol\Imap::unselect()` leaves the selected folder without expunging, when UNSELECT is offered or IMAP4rev2 is enabled. CLOSE is never sent: selecting another folder and LOGOUT don't expunge either (RFC 3501, §6.4.2). `UidPlusCommandTest`, and against Dovecot in `ImapTest` |

## POP3: RFC 1939, RFC 2449, RFC 2595, RFC 5034

| Requirement | Verdict | Evidence and notes |
| --- | --- | --- |
| RFC 1939 Commands, multi-line responses, dot-unstuffing, APOP | Conforms | `retrievesAMessageAndRemovesDotStuffing`, `fallsBackToUserAndPassWhenApopIsRefused` |
| RFC 2449 CAPA | Conforms | `refusesToContinueInPlainTextWhenCapaIsNotSupported` |
| RFC 2595 STLS | Conforms | Required by default. `refusesToContinueInPlainTextWhenStlsIsNotOffered` |
| RFC 5034 SASL | Partial | XOAUTH2, for Gmail and Microsoft 365, through the `auth` setting or `Pop3\Xoauth2\Microsoft`, and SCRAM-SHA-256 (`AuthenticateScramTest`, and against Dovecot in `Pop3ScramTest`). No other mechanism. |

## TLS: RFC 8996, RFC 9325, RFC 9525

| Requirement | Verdict | Evidence and notes |
| --- | --- | --- |
| RFC 8996 No TLS 1.0 or 1.1 | Conforms | TLS 1.2 and 1.3 only. `offersOnlyTls12And13` |
| RFC 9325 Certificates verified by default | Conforms | `refusesAnUntrustedCertificateByDefault`, `verifiesServerCertificateByDefault` |
| RFC 9525 / 7817 Server identity checked against the host name | Conforms | PHP's `verify_peer_name`, on by default. |

## Mailbox formats

| Requirement | Verdict | Evidence and notes |
| --- | --- | --- |
| Maildir: deliver in `tmp`, link into `new`, unique names | Conforms | Exclusive create, fsync, link. `makesUniqueNames`, `refusesToDeliverOverExistingFile` |
| Maildir: escape `/` and `:` in the host name | Conforms | `escapesHostInUniqueName` |
| Maildir: flags in `:2,` info | Conforms | `movesMessageFromNewToCurWhenFlagged`, `readsFlags` |
| Maildir++ quota (`maildirsize`) | Conforms | `checksQuotaFromMaildirsize`, `addsQuotaEntryWhenStoring` |
| mbox: mboxo and mboxrd reading, LF and CRLF files | Conforms | `unquotesFromLinesInMboxrd`, `keepsQuotedFromLinesInMboxo` |
| mbox: mboxcl and mboxcl2 (Content-Length) | Not implemented | |
| mbox: writing | Not implemented | Mbox is read-only, as in laminas-mail. |

## Not verified

These need real servers or mail clients and were not tested:

- delivery through Postfix, Exim, Sendmail, Gmail and Microsoft 365, and reading
  from Dovecot and Cyrus;
- how mail clients display RFC 2231 filenames, long encoded words and
  continuation-line values.

## Gaps to close

| Priority | Gap | Size |
| --- | --- | --- |
| 1 | IMAP4rev2 when servers need it (#14) | Large |
