# contenir-mail

Compose, parse, store and send text and MIME-compliant multipart e-mail messages.

```bash
$ composer require contenir/contenir-mail
```

- [Introduction](intro.md)
- [Migrating from laminas-mail and laminas-mime](migrating.md): the silent changes first, then the full API mapping
- Messages: [intro and usage](message/intro.md), [attachments](message/attachments.md),
  [character sets](message/character-sets.md), [DKIM signing](message/dkim.md)
- Transports: [usage](transport/intro.md), [SMTP options](transport/smtp-options.md),
  [sending multiple messages](transport/smtp-multiple-send.md),
  [SMTP authentication](transport/smtp-authentication.md),
  [file transport options](transport/file-options.md)
- [Reading and storing mail](read.md)
- MIME: [introduction](mime/intro.md), [parts](mime/part.md), [multiparts](mime/multipart.md)
- [Security](security.md): threat model, protections and their tests, vulnerability history
- [Standards](standards.md): the RFCs implemented and how closely each is followed
- [laminas-mail issues](laminas-issues.md): the issues reported against laminas-mail, and how each one affects contenir-mail
- [Naming conventions](conventions.md): how the public API is named, for contributors and reviewers
