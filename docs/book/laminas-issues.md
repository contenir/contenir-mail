# laminas-mail issues

contenir-mail continues laminas-mail and laminas-mime, so the issues reported
against them were checked against this package. This page records the results.
It covers the 64 issues open on laminas-mail on 8 October 2026 and the closed
issues that describe a defect. laminas-mime had no open issues.

Each defect was reproduced with a probe script against contenir-mail. Fixed
issues have a regression test, named below; the issue number is also in the
test's docblock.

## Fixed

These issues affected contenir-mail as well, and are fixed.

| Issue | Problem | Fix and test |
| --- | --- | --- |
| [#58](https://github.com/laminas/laminas-mail/issues/58), [#209](https://github.com/laminas/laminas-mail/issues/209), [#234](https://github.com/laminas/laminas-mail/issues/234), [#263](https://github.com/laminas/laminas-mail/issues/263) | Raw Latin-1 or Windows-1252 bytes in a header made the whole message unreadable: "Invalid header value detected". | A header that is not UTF-8 is read as Windows-1252, as mail clients do; bytes Windows-1252 leaves undefined become U+FFFD. `HeadersLegacyCharsetTest` |
| [#62](https://github.com/laminas/laminas-mail/issues/62), [#188](https://github.com/laminas/laminas-mail/issues/188) | An IMAP quoted string ended at its first `"`, cutting an ENVELOPE subject short or misreading a folder name ending in `"`. | Quoted strings follow RFC 3501, with `\"` and `\\` unescaped. `QuotedStringTest` |
| [#70](https://github.com/laminas/laminas-mail/issues/70) | Parentheses inside a quoted display name were read as a comment: `"Jo (work) Bloggs"` became `Jo  Bloggs`. | Comments are found outside quoted strings only. `keepsParenthesesInQuotedNameAsText` |
| [#76](https://github.com/laminas/laminas-mail/issues/76), [#221](https://github.com/laminas/laminas-mail/issues/221) | One line that is not a header made a message unreadable, and stopped a `foreach` over a mailbox. | Reading drops such a line, with its continuation lines. Composing, and checking text that is written back, stay strict. `skipsLineNotMatchingHeaderFormatWhenReading`, `readsMessageWithMalformedHeaderLine` |
| [#187](https://github.com/laminas/laminas-mail/issues/187) | A refused POP3 request only said "last request failed". | The server's reason follows, with control characters removed: `last request failed: [IN-USE] mailbox locked`. `reportsTheReasonTheServerGives` |
| [#211](https://github.com/laminas/laminas-mail/issues/211) | Every Content-Type parameter was folded onto its own line, however short the header. | Parameters stay on the first line while they fit in 76 characters, as Content-Disposition already did. `ContentTypeTest` |

## Fixed by the redesign

These issues were already resolved by the redesign.

| Issue | Problem | How contenir-mail handles it |
| --- | --- | --- |
| [#57](https://github.com/laminas/laminas-mail/issues/57), [#67](https://github.com/laminas/laminas-mail/issues/67), [#180](https://github.com/laminas/laminas-mail/issues/180) | Addresses with non-ASCII local parts were refused. | Written as UTF-8 (RFC 6532) and sent with SMTPUTF8 (RFC 6531) when the server offers it. |
| [#112](https://github.com/laminas/laminas-mail/issues/112), [#243](https://github.com/laminas/laminas-mail/issues/243) | Magic methods and their documentation disagreed. | The magic accessors are gone; methods are typed. |
| [#160](https://github.com/laminas/laminas-mail/issues/160), [#161](https://github.com/laminas/laminas-mail/issues/161) | Method signatures and docblocks disagreed. | Typed signatures, checked by Mago. |
| [#197](https://github.com/laminas/laminas-mail/issues/197), [#1](https://github.com/laminas/laminas-mail/issues/1) | No OAuth 2 sign-in. | XOAUTH2 for SMTP, IMAP and POP3, with a token provider. |
| [#230](https://github.com/laminas/laminas-mail/issues/230), [#65](https://github.com/laminas/laminas-mail/issues/65) | A single HTML part needed a MIME message to be built by hand. | `Message::setHtml()` and `setText()`. |
| [#236](https://github.com/laminas/laminas-mail/issues/236) | The SMTP reply code was lost. | The reply code is the exception code. |
| [#258](https://github.com/laminas/laminas-mail/issues/258) | The password was sent to an IMAP server that advertises LOGINDISABLED. | LOGIN is refused before the password is sent. |
| [#265](https://github.com/laminas/laminas-mail/issues/265) | Content headers went stale when the body changed. | Content headers are built from the body each time they are read. |
| [#12](https://github.com/laminas/laminas-mail/issues/12) | No Message-ID was generated. | A Message-ID is added on the sender's domain. |
| [#38](https://github.com/laminas/laminas-mail/issues/38), [#11](https://github.com/laminas/laminas-mail/issues/11) | A literal in a LIST response looped forever. | Literals are read by length. Probed with `* LIST (\HasNoChildren) "/" {16}`. |
| [#52](https://github.com/laminas/laminas-mail/issues/52) | The SMTP destructor threw when the server had already gone. | A failed QUIT is ignored when disconnecting. Probed. |
| [#78](https://github.com/laminas/laminas-mail/issues/78) | Subject encoding depended on the order setters were called in. | There is no encoding setter: text that is not ASCII is always encoded as UTF-8. |
| [#152](https://github.com/laminas/laminas-mail/issues/152) | `has('00000')` matched a header named `0`. | Names are compared as strings. |

## Not affected

Probes show contenir-mail behaves correctly for these issues:

- **Display names, quoting and encoding:** [#14](https://github.com/laminas/laminas-mail/issues/14), [#21](https://github.com/laminas/laminas-mail/issues/21), [#22](https://github.com/laminas/laminas-mail/issues/22), [#222](https://github.com/laminas/laminas-mail/issues/222), [#250](https://github.com/laminas/laminas-mail/issues/250), [#273](https://github.com/laminas/laminas-mail/issues/273).
- **Encoded words and folding:** [#74](https://github.com/laminas/laminas-mail/issues/74), [#173](https://github.com/laminas/laminas-mail/issues/173).
- **Header parsing:** [#8](https://github.com/laminas/laminas-mail/issues/8), [#23](https://github.com/laminas/laminas-mail/issues/23), [#25](https://github.com/laminas/laminas-mail/issues/25), [#35](https://github.com/laminas/laminas-mail/issues/35), [#71](https://github.com/laminas/laminas-mail/issues/71) (kept as a GenericHeader).
- **Storage:** [#37](https://github.com/laminas/laminas-mail/issues/37) (Thunderbird mbox), [#50](https://github.com/laminas/laminas-mail/issues/50) (Maildir).
- **Transports:**
  - [#17](https://github.com/laminas/laminas-mail/issues/17), [#26](https://github.com/laminas/laminas-mail/issues/26) and [#27](https://github.com/laminas/laminas-mail/issues/27): a `-f` in the parameters is kept, and no second one is added.
  - [#68](https://github.com/laminas/laminas-mail/issues/68): Cc and Bcc are SMTP recipients.
  - [#72](https://github.com/laminas/laminas-mail/issues/72): no duplicate Subject.
  - [#240](https://github.com/laminas/laminas-mail/issues/240): an empty subject, not null.
  - [#247](https://github.com/laminas/laminas-mail/issues/247): recipients are passed as arguments, never read from the message with `-t`.
- **Reported earlier, also not affected:** [#4](https://github.com/laminas/laminas-mail/issues/4), [#6](https://github.com/laminas/laminas-mail/issues/6), [#43](https://github.com/laminas/laminas-mail/issues/43), [#44](https://github.com/laminas/laminas-mail/issues/44), [#45](https://github.com/laminas/laminas-mail/issues/45), [#51](https://github.com/laminas/laminas-mail/issues/51), [#54](https://github.com/laminas/laminas-mail/issues/54), [#55](https://github.com/laminas/laminas-mail/issues/55), [#56](https://github.com/laminas/laminas-mail/issues/56), [#59](https://github.com/laminas/laminas-mail/issues/59), [#61](https://github.com/laminas/laminas-mail/issues/61), [#79](https://github.com/laminas/laminas-mail/issues/79), [#119](https://github.com/laminas/laminas-mail/issues/119), [#120](https://github.com/laminas/laminas-mail/issues/120).

## Enhancements not yet offered

These requests describe features contenir-mail does not have.

| Issue | Request |
| --- | --- |
| [#5](https://github.com/laminas/laminas-mail/issues/5), [#63](https://github.com/laminas/laminas-mail/issues/63), [#185](https://github.com/laminas/laminas-mail/issues/185), [#206](https://github.com/laminas/laminas-mail/issues/206) | Custom TLS stream-context options beyond `verify_peer`, such as `allow_self_signed` or a CA file. Coming in 0.3.0 as typed settings (#16). |
| [#64](https://github.com/laminas/laminas-mail/issues/64), [#146](https://github.com/laminas/laminas-mail/issues/146), [#148](https://github.com/laminas/laminas-mail/issues/148) | A non-strict address validation mode. Reading is already lenient: an address header that fails is kept as a GenericHeader. `Address::lenient()` is coming in 0.3.0 (#18). |
| [#73](https://github.com/laminas/laminas-mail/issues/73), [#229](https://github.com/laminas/laminas-mail/issues/229) | Text and HTML accessors on a stored message; extracting the reply from a thread. |
| [#205](https://github.com/laminas/laminas-mail/issues/205) | Adding or removing single flags in storage. `Protocol\Imap::store()` supports `+` and `-`; `Storage\Imap` only replaces flags. |
| [#9](https://github.com/laminas/laminas-mail/issues/9) | DKIM signing. |
| [#248](https://github.com/laminas/laminas-mail/issues/248) | SCRAM authentication. |
| [#157](https://github.com/laminas/laminas-mail/issues/157) | Reading TNEF (winmail.dat) attachments. |
| [#177](https://github.com/laminas/laminas-mail/issues/177), [#216](https://github.com/laminas/laminas-mail/issues/216) | Paging large folders efficiently; IMAP SORT. |
| [#33](https://github.com/laminas/laminas-mail/issues/33) | JMAP. |

## Out of scope

These issues are about laminas-mail's own CI, releases, PHP versions,
dependencies or documentation, or are usage questions:

- **CI and project process:** [#13](https://github.com/laminas/laminas-mail/issues/13), [#20](https://github.com/laminas/laminas-mail/issues/20), [#69](https://github.com/laminas/laminas-mail/issues/69), [#113](https://github.com/laminas/laminas-mail/issues/113), [#130](https://github.com/laminas/laminas-mail/issues/130), [#131](https://github.com/laminas/laminas-mail/issues/131), [#133](https://github.com/laminas/laminas-mail/issues/133), [#194](https://github.com/laminas/laminas-mail/issues/194), [#207](https://github.com/laminas/laminas-mail/issues/207), [#224](https://github.com/laminas/laminas-mail/issues/224), [#239](https://github.com/laminas/laminas-mail/issues/239).
- **PHP versions and dependencies:** [#39](https://github.com/laminas/laminas-mail/issues/39), [#40](https://github.com/laminas/laminas-mail/issues/40), [#87](https://github.com/laminas/laminas-mail/issues/87), [#114](https://github.com/laminas/laminas-mail/issues/114), [#116](https://github.com/laminas/laminas-mail/issues/116), [#175](https://github.com/laminas/laminas-mail/issues/175), [#249](https://github.com/laminas/laminas-mail/issues/249), [#252](https://github.com/laminas/laminas-mail/issues/252), [#270](https://github.com/laminas/laminas-mail/issues/270).
- **Documentation:** [#16](https://github.com/laminas/laminas-mail/issues/16), [#24](https://github.com/laminas/laminas-mail/issues/24), [#144](https://github.com/laminas/laminas-mail/issues/144), [#189](https://github.com/laminas/laminas-mail/issues/189).
- **Usage questions, or reports that could not be reproduced:** [#10](https://github.com/laminas/laminas-mail/issues/10), [#30](https://github.com/laminas/laminas-mail/issues/30), [#32](https://github.com/laminas/laminas-mail/issues/32), [#46](https://github.com/laminas/laminas-mail/issues/46), [#48](https://github.com/laminas/laminas-mail/issues/48), [#66](https://github.com/laminas/laminas-mail/issues/66), [#85](https://github.com/laminas/laminas-mail/issues/85), [#86](https://github.com/laminas/laminas-mail/issues/86), [#118](https://github.com/laminas/laminas-mail/issues/118), [#156](https://github.com/laminas/laminas-mail/issues/156), [#186](https://github.com/laminas/laminas-mail/issues/186), [#210](https://github.com/laminas/laminas-mail/issues/210), [#260](https://github.com/laminas/laminas-mail/issues/260).
- **SMTP regressions in specific laminas-mail releases, mostly reported through Magento:** [#158](https://github.com/laminas/laminas-mail/issues/158), [#162](https://github.com/laminas/laminas-mail/issues/162), [#168](https://github.com/laminas/laminas-mail/issues/168), [#169](https://github.com/laminas/laminas-mail/issues/169), [#170](https://github.com/laminas/laminas-mail/issues/170), [#184](https://github.com/laminas/laminas-mail/issues/184). The connection code they concern was rewritten; see [Security](security.md).

The remaining closed issues were pull requests or API changes, such as
argument checks, fluent setters and removed dependencies. The redesign
replaced the code they touched, and they were not re-tested one by one:
[#2](https://github.com/laminas/laminas-mail/issues/2), [#3](https://github.com/laminas/laminas-mail/issues/3), [#7](https://github.com/laminas/laminas-mail/issues/7), [#15](https://github.com/laminas/laminas-mail/issues/15), [#18](https://github.com/laminas/laminas-mail/issues/18), [#19](https://github.com/laminas/laminas-mail/issues/19), [#28](https://github.com/laminas/laminas-mail/issues/28), [#29](https://github.com/laminas/laminas-mail/issues/29), [#34](https://github.com/laminas/laminas-mail/issues/34), [#36](https://github.com/laminas/laminas-mail/issues/36), [#41](https://github.com/laminas/laminas-mail/issues/41), [#42](https://github.com/laminas/laminas-mail/issues/42), [#47](https://github.com/laminas/laminas-mail/issues/47), [#49](https://github.com/laminas/laminas-mail/issues/49), [#53](https://github.com/laminas/laminas-mail/issues/53), [#60](https://github.com/laminas/laminas-mail/issues/60), [#121](https://github.com/laminas/laminas-mail/issues/121).
