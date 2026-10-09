# Reading and Storing Mail

contenir-mail reads mail from mbox files, maildirs, IMAP and POP3 servers,
and stores mail in maildirs and on IMAP servers. Every storage shares the same
API for counting and fetching messages; some add folders, flags or writing.

Feature               | Mbox     | Maildir  | Pop3     | IMAP
--------------------- | -------- | -------- | -------- | ----
Storage type          | local    | local    | remote   | remote
Fetch message         | Yes      | Yes      | Yes      | Yes
Fetch MIME part       | Yes      | Yes      | Yes      | Yes
Folders               | Yes      | Yes      | No       | Yes
Create message/folder | No       | Yes      | No       | Yes
Flags                 | No       | Yes      | No       | Yes
Quota                 | No       | Yes      | No       | No

Storages return `Contenir\Mail\Storage\Message` objects. A stored message is
also a MIME part (`Contenir\Mail\Mime\PartInterface`), the same interface the
parts you compose for sending implement, so a message you read can be
forwarded, attached or stored elsewhere without parsing it again.

## Basic POP3 example

```php
use Contenir\Mail\Storage\Pop3;

$mail = new Pop3([
    'host'     => 'pop.example.com',
    'user'     => 'test',
    'password' => 'test',
]);

echo $mail->countMessages() . " messages found\n";
foreach ($mail as $number => $message) {
    printf("%d: mail from %s: %s\n", $number, $message->getFrom()->first()?->getEmail(), $message->getSubject());
}
```

## Settings

Each storage takes its settings as an array (or any iterable), as in
laminas-mail, or as a typed, immutable config object:

Storage                          | Config                                   | Keys
-------------------------------- | ---------------------------------------- | ----
`Storage\Mbox`                   | `Storage\MboxConfig`                     | `filename`, `format`
`Storage\Folder\Mbox`            | `Storage\Folder\MboxConfig`              | `dirname`, `folder`, `format`
`Storage\Maildir`                | `Storage\MaildirConfig`                  | `dirname`
`Storage\Folder\Maildir`         | `Storage\Folder\MaildirConfig`           | `dirname`, `delim`, `folder`
`Storage\Writable\Maildir`       | `Storage\Writable\MaildirConfig`         | `dirname`, `delim`, `folder`, `create`, `directory_mode`, `file_mode`
`Storage\Imap`                   | `Storage\ImapConfig`                     | connection keys, `user`, `password`, `folder`, `auth`, `prefer_imap4_rev2`
`Storage\Pop3`                   | `Storage\Pop3Config`                     | connection keys, `user`, `password`, `auth`

Keys may be written in snake_case, camelCase or kebab-case. An unknown key, or
a value of the wrong type, throws an exception that names the key, so a typo
never silently falls back to a default.

```php
use Contenir\Mail\Storage\Folder\MaildirConfig;
use Contenir\Mail\Storage\Folder\Maildir;

$mail = new Maildir(new MaildirConfig(dirname: '/home/test/Maildir', folder: 'INBOX.Archive'));
// the same:
$mail = new Maildir(['dirname' => '/home/test/Maildir', 'folder' => 'INBOX.Archive']);
```

Paths must be local file system paths: a stream wrapper such as `phar://`,
`http://` or `data:` is refused, as is a NUL byte.

## Using local storage: mbox and maildir

```php
use Contenir\Mail\Storage\Maildir;
use Contenir\Mail\Storage\Mbox;

$mail = new Mbox(['filename' => '/home/test/mail/inbox']);
$mail = new Maildir(['dirname' => '/home/test/Maildir']);
```

Both constructors throw a `Contenir\Mail\Storage\Exception\ExceptionInterface`
if the storage cannot be read.

Mbox files may use CRLF or bare LF line breaks. Messages are read from the
file only when their content is asked for, and stay readable after the
storage is closed.

### mbox variants

Each message in an mbox file starts with a `From ` line, so a body line that
starts with `From ` is written with a `>` in front. The variants differ in
what happens to lines that already start with `>From `:

- **mboxo** (the default, `MboxFormat::Mboxo`): only `From ` lines are quoted,
  so the escaping cannot be undone and body lines are read as written.
- **mboxrd** (`MboxFormat::Mboxrd`): every `>*From ` line gets one more `>`,
  and reading removes one again, restoring the body exactly. Use this for
  files written by mutt, procmail or Postfix's `local`.

```php
use Contenir\Mail\Storage\Mbox;
use Contenir\Mail\Storage\MboxFormat;

$mail = new Mbox(['filename' => '/var/mail/test', 'format' => 'mboxrd']);
```

The Content-Length variants (mboxcl, mboxcl2) are read as mboxo.

## Using remote storage: IMAP and POP3

Both need at least a user. The connection settings are those of
`Contenir\Mail\Protocol\ConnectionConfig`:

Key           | Default        | Meaning
------------- | -------------- | -------
`host`        | `127.0.0.1`    | Server name or address
`port`        | standard port  | 143 or 993 for IMAP, 110 or 995 for POP3
`security`    | `starttls`     | `starttls`, `tls` (TLS from the start) or `none`
`verify_peer` | `true`         | Check the server's certificate
`timeout`     | `30`           | Seconds
`cafile`, `capath` | none | Certificate authorities to trust, in place of the system's
`peer_name`         | the host       | The name the server's certificate must carry
`allow_self_signed` | `false`        | Accept a self-signed certificate; weakens verification
`local_cert`, `local_pk` | none     | A client certificate, and its key if in a separate file

The TLS settings apply to TLS from the start and to STARTTLS. See the security
page for their risks.

Connections use STARTTLS unless told otherwise. The laminas-mail keys still
work: `ssl` set to `SSL` means `tls`, `TLS` means `starttls`, and `false` or
`none` means a plain connection; any other value is refused. `novalidatecert`
set to `true` turns peer verification off. Give each setting under one name
only.

```php
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Storage\Imap;
use Contenir\Mail\Storage\ImapConfig;
use Contenir\Mail\Storage\Pop3;

$mail = new Imap([
    'host'     => 'imap.example.com',
    'user'     => 'test',
    'password' => $password,
]);

$mail = new Imap(new ImapConfig(
    new ConnectionConfig(host: 'imap.example.com', security: Security::Tls),
    user: 'test',
    password: $password,
    folder: 'Archive',
));

// POP3 on a non-standard port, TLS from the start, as laminas-mail wrote it
$mail = new Pop3([
    'host'     => 'pop.example.com',
    'port'     => 1995,
    'user'     => 'test',
    'password' => $password,
    'ssl'      => 'SSL',
]);
```

Passwords are marked `#[SensitiveParameter]`, so they do not appear in stack
traces, and `var_dump()` of a config shows them masked.

After signing in, `Storage\Imap` turns on IMAP4rev2 (RFC 9051) when the server
offers it, or else UTF8=ACCEPT. Set `prefer_imap4_rev2` to `false`
(`ImapConfig::$preferImap4Rev2`) to keep the session IMAP4rev1, as for a server
whose IMAP4rev2 support is unreliable.

### Signing in with an access token (OAuth 2.0)

Microsoft 365 accepts only OAuth for IMAP and POP3. Gmail accepts an app
password, or OAuth. To use OAuth, give an `auth` setting in place of the
password. It takes the same `XOAuth2` authenticator as the SMTP transport, so
one token signs in to all three protocols. The `user` defaults to the token's
username.

```php
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
use Contenir\Mail\Storage\Imap;
use Contenir\Mail\Storage\Pop3;

$mail = new Imap([
    'host'     => 'outlook.office365.com',
    'security' => 'tls',
    'auth'     => ['username' => 'jo@example.com', 'access_token' => $accessToken],
]);

// A Closure is called for a fresh token at each sign-in
$mail = new Pop3([
    'host'     => 'pop.gmail.com',
    'security' => 'tls',
    'auth'     => new XOAuth2('jo@gmail.com', static fn(): string => $tokens->fresh()),
]);
```

IMAP sends the token with the `AUTHENTICATE` command when the server offers
SASL-IR (RFC 4959), and after the server's continuation otherwise. When a
token is refused, the client finishes the exchange as RFC 7628 requires. It
then throws an error with the server's reason, such as "The server refused the
access token (status 400): [AUTHENTICATIONFAILED] Invalid credentials". An
expired token or a mailbox with IMAP turned off shows up there.

### Signing in with SCRAM-SHA-256

A server that offers SCRAM-SHA-256, such as Dovecot, can check the password
without it crossing the wire, and proves in return that it knows the password.
Give the `ScramSha256` authenticator, or its settings with `type`, under `auth`.
Settings without a `type` are read as XOAUTH2, as before.

```php
use Contenir\Mail\Protocol\Smtp\Auth\ScramSha256;
use Contenir\Mail\Storage\Imap;
use Contenir\Mail\Storage\Pop3;

$mail = new Imap([
    'host' => 'imap.example.com',
    'auth' => ['type' => 'scram-sha-256', 'username' => 'jo', 'password' => $password],
]);

$mail = new Pop3([
    'host' => 'pop.example.com',
    'auth' => new ScramSha256('jo', $password),
]);
```

IMAP requires `AUTH=SCRAM-SHA-256` among the server's capabilities, and sends
the first message with the command when SASL-IR is offered. The client checks
the server's signature before it finishes the exchange, and throws if the
signature is wrong or missing. Channel binding (`-PLUS`) is not supported; see
[SMTP authentication](transport/smtp-authentication.md#scram-sha-256) for the
reasons and for how non-ASCII credentials are prepared.

Connection errors throw `Contenir\Mail\Protocol\Exception\ExceptionInterface`;
a failed login throws `Contenir\Mail\Storage\Exception\RuntimeException`.

## Fetching, counting and removing messages

Messages are numbered from 1. Fetch one with `getMessage()`, count them with
`countMessages()` (or `count()`), and iterate the storage to get them all:

```php
$message = $mail->getMessage($number);

echo count($mail) . " messages\n";

foreach ($mail as $number => $message) {
    // ...
}

$sizes = $mail->getSizes();       // [number => bytes]
$size  = $mail->getSize($number); // bytes
```

Numbers change when messages are removed. Unique IDs do not, so use them to
refer to a message across requests:

```php
$id     = $mail->getUniqueId($number);
$number = $mail->getNumberByUniqueId($id);
$mail->removeMessage($number);
```

`getUniqueIds()` lists every unique ID by number. Mbox messages have no unique
IDs, so their numbers serve; a POP3 server without UIDL does the same.

`getRawHeader()` and `getRawContent()` return a message's header block and
body as stored. `getCapabilities()` lists what the storage supports.

### Paging through a large IMAP folder

Iterating a folder fetches messages one at a time. To show a page of a large
folder, let the server sort the numbers, then fetch the page in one request:

```php
$newest = $mail->getSortedNumbers('REVERSE ARRIVAL');       // list of numbers
$page   = $mail->getMessages(...array_slice($newest, 0, 50)); // [number => Message]

foreach ($page as $number => $message) {
    echo $number, ' ', $message->getSubject(), "\n";
}
```

`getMessages()` fetches the flags and headers of every message asked for in
one FETCH, and each body only when it's read. A number the server sends no
headers for is left out. `getSortedNumbers()` takes sort keys from RFC 5256
(`ARRIVAL`, `CC`, `DATE`, `FROM`, `SIZE`, `SUBJECT`, `TO`) and RFC 5957
(`DISPLAYFROM`, `DISPLAYTO`), each optionally after `REVERSE`, and needs a
server that offers SORT. Without it, use number ranges: the highest numbers
are the most recently added.

`countMessages()` asks the server for the count with ESEARCH (RFC 4731) when
it can, instead of receiving every matching number. At the protocol level,
`Protocol\Imap::sort()` and `searchCount()` take any search criteria.

Storages hold open files or connections, so they cannot be serialized.
`close()` releases them, and the destructor calls it.

### Waiting for new mail (IDLE)

An IMAP server that offers IDLE (RFC 2177), as most do, tells a listening
client about changes to the selected folder as they happen. `idle()` returns a
generator of events:

```php
use Contenir\Mail\Storage\Idle;

$last = $mail->countMessages();

foreach ($mail->idle(timeout: 600) as $event) {
    if ($event instanceof Idle\MessageCountChanged && $event->count > $last) {
        $new  = $mail->getMessages(...range($last + 1, $event->count));
        $last = $event->count;
    }
}
```

The events are `Idle\MessageCountChanged` (the folder now holds `$count`
messages), `Idle\MessageExpunged` (message `$number` was removed, and the
ones after it moved down), `Idle\RecentCountChanged` (`$count` messages are
new to this session) and `Idle\FlagsChanged` (message `$number` now has
`$flags`). They all implement `Idle\EventInterface`. Other responses are
skipped.

Nothing is sent until the loop starts. The loop ends once the timeout has
passed, 29 minutes by default, as RFC 2177 asks clients to end IDLE at least
that often. To keep listening, call `idle()` again:

```php
while (true) {
    foreach ($mail->idle() as $event) {
        // ...
    }
}
```

Breaking out of the loop ends IDLE, and so does the next command, so the
mailbox can be used inside the loop, as above. If the server ends the
connection with BYE, iterating throws a `Protocol\Exception\RuntimeException`.
A server that offers neither IDLE nor IMAP4rev2 throws the same when `idle()`
is called.

At the protocol level, `Protocol\Imap::idle()` yields each untagged response
as its tokens, such as `['3', 'EXISTS']`. Both take a PSR-20 clock as their
second argument, for tests. Waiting uses
`ConnectionInterface::waitUntilReadable()`, so a quiet server is not a
timeout.

## Working with messages

```php
$message = $mail->getMessage(1);

echo $message->getSubject();                  // decoded, or null
$from    = $message->getFrom();               // Contenir\Mail\AddressList
$to      = $message->getTo();
$cc      = $message->getCc();
$replyTo = $message->getReplyTo();
$date    = $message->getDate();               // DateTimeImmutable, or null
$id      = $message->getMessageId();          // without angle brackets, or null
```

Every header is in `getHeaders()`, an immutable `Contenir\Mail\Headers`
collection. `get()` returns the first header of a name (or `null`), and
`all()` every header of that name:

```php
foreach ($message->getHeaders() as $header) {
    printf("%s: %s\n", $header->getFieldName(), $header->getFieldValue());
}

$received = $message->getHeaders()->all('Received');
$type     = $message->getHeaders()->get('Content-Type')?->getFieldValue();
```

### Content and parts

`getContent()` returns the content decoded from its Content-Transfer-Encoding
(base64 or quoted-printable), in the part's own character set, which
`getCharset()` gives. `getEncodedContent()` returns it as transferred. Both
are empty for a multipart.

```php
$part = $message;
while ($part->isMultipart()) {
    $part = $part->getPart(1);   // parts are numbered from 1
}

echo 'Type: ' . $part->getContentType() . "\n";
echo $part->getContent();
```

`getParts()` lists a multipart's parts and `countParts()` counts them.
Iterating a message or part gives its parts; with `RecursiveIteratorIterator`
you walk nested parts too:

```php
use RecursiveIteratorIterator;

foreach (new RecursiveIteratorIterator($mail->getMessage(1)) as $part) {
    if ('text/plain' === $part->getContentType()) {
        echo $part->getContent();
        break;
    }
}
```

### Text and HTML bodies

`getTextBody()` and `getHtmlBody()` return the body of a message or part as
UTF-8, or null when it has no such part:

```php
$text = $message->getTextBody();
$html = $message->getHtmlBody();
```

Each finds the first `text/plain` (or `text/html`) part, searching depth
first through `multipart/mixed` and `multipart/related`. Parts with a
`Content-Disposition` of `attachment` are skipped, so an attached text file
is never taken for the body. In `multipart/alternative` the last matching
part wins, as the richest. The transfer encoding is decoded, and the text is
converted to UTF-8 from the declared charset when it is one mail is written
in. Text with no charset, an unlisted one, or one that does not convert is
returned with invalid bytes replaced by U+FFFD.

The HTML is returned exactly as sent. It is neither sanitised nor safe to
show: sanitise it, for example with an HTML purifier, before rendering it.

Bodies load lazily: headers are read with the message, and the body is read
from the file, or fetched from the server, only when content or parts are
asked for. A multipart is split into its parts once, on first use.

Hostile mail is read safely: the header block may be at most 1 MiB and hold
1000 headers, parts nest at most 32 deep, a multipart holds at most 1000
parts, and a missing closing boundary ends the last part at the end of the
body. Long lines are read in pieces, so memory stays bounded.

### Attachments and untrusted text

`getFilename()` returns the file name the sender gave, from
Content-Disposition or the Content-Type `name`. It is untrusted: it may hold
`../`, path separators, control characters or bidirectional overrides. Use
`getSafeFilename()` to store or show it:

```php
foreach (new RecursiveIteratorIterator($message) as $part) {
    if (null !== $part->getFilename()) {
        file_put_contents("/srv/attachments/{$part->getSafeFilename()}", $part->getContent());
    }
}
```

Display names and comments are kept as written too. Pass them through
`Contenir\Mail\Header\SafeText::addressList()` or `SafeText::display()` before
showing them, to remove control and bidirectional characters.

### TNEF attachments (winmail.dat)

Outlook and Exchange sometimes wrap a message's attachments in a TNEF
container: an `application/ms-tnef` part, usually named `winmail.dat`, that
other mail clients can't open. `getTnefContents()` finds the first such part
of a message, searching depth first, and reads it. It returns null when there
is none:

```php
$tnef = $message->getTnefContents();
foreach ($tnef?->attachments ?? [] as $attachment) {
    file_put_contents("/srv/attachments/{$attachment->filename}", $attachment->content);
}
```

The result is a `Contenir\Mail\Storage\Tnef\Contents` with three properties:

- `attachments`: a list of `Tnef\TnefAttachment`, each with:
  - `filename`: the long file name, or else the short title, already passed
    through `SafeText::filename()`;
  - `content`: the file's bytes;
  - `type`: the media type the sender gave, in lower case, or
    `application/octet-stream` when it gave none, or something other than a
    plain `type/subtype`.
- `text`: the plain-text body as UTF-8, or null.
- `rtf`: the RTF body, decompressed, or null. It's returned as sent and isn't
  safe to render as it is.

A part is TNEF when its type is `application/ms-tnef` or
`application/vnd.ms-tnef`, or its file name is `winmail.dat`. To read a part
found some other way, or a `winmail.dat` file, use the reader directly:

```php
use Contenir\Mail\Storage\Tnef\Reader;

$contents = (new Reader())->read($part->getContent());
```

TNEF is a binary format, and the container comes from the sender, so the
reader treats it as hostile. It refuses the container with a
`Storage\Exception\RuntimeException`, rather than returning part of it, when:

- a length runs past the end of the data;
- a record's checksum doesn't match;
- the container holds more than 100 attachments;
- it would produce more than 64 MiB of attachments, text and RTF together.

Both limits can be changed:

```php
$tnef = $message->getTnefContents(new Reader(maxAttachments: 20, maxBytes: 10 * 1024 * 1024));
```

To forward an attachment, build a part from it with
`Mime\Attachment::fromString($attachment->content, $attachment->filename, $attachment->type)`.

### Forwarding and attaching

`toString()` writes a message or part back out. Headers that were not
changed keep the exact text they were read with, folding and encoded words
included, so DKIM signatures still verify:

```php
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Mime\TransferEncoding;

$forward = (new Message())
    ->setSubject('Fwd: ' . $stored->getSubject())
    ->setText('See the attached message.')
    ->attach(new Part($stored->toString(), 'message/rfc822', TransferEncoding::EightBit));
```

A read part can be attached directly, since it is a `PartInterface`.

## Flags

Maildir and IMAP keep flags for each message. The common ones are cases of
the `Contenir\Mail\Storage\Flag` enum: `Seen`, `Answered`, `Flagged`,
`Deleted`, `Draft`, `Recent` and `Passed`. `Passed`, Maildir's name, is the
IMAP keyword `$Forwarded`, and `Flag::Forwarded` names the same case.
Keywords, such as IMAP `$Junk` or a Maildir keyword letter, stay strings.
`Flag::normalize()` turns an IMAP name into its case, and leaves a keyword as
it is.

```php
use Contenir\Mail\Storage\Flag;

foreach ($mail as $message) {
    if ($message->hasFlag(Flag::Seen)) {
        continue;
    }

    echo ($message->hasFlag(Flag::Flagged) ? '! ' : '  ') . $message->getSubject() . "\n";
}

// IMAP names work too
$message->hasFlag('\Seen');
$message->hasFlag('$Junk');

// messages with every flag given
$flagged = $mail->countMessages(Flag::Flagged);
```

`getFlags()` lists a message's flags, cases and strings alike.

`Recent` is unreliable over IMAP: IMAP4rev2 (RFC 9051) removed `\Recent`, so a
server with IMAP4rev2 enabled never reports it, and IMAP4rev1 servers report
it to one session only. To find new mail, look for messages without `Seen`,
or compare `FolderStatus::$uidNext` with the value from the last check.

## Folders

All storages but POP3 have folders. `getFolders()` returns the folder tree as
a `Contenir\Mail\Storage\Folder`, or the subtree of the folder named.

IMAP folder names are always given and returned as UTF-8, such as
`Entwürfe` or `R&D`. The client writes them in modified UTF-7 for an
IMAP4rev1 server, and as they are once IMAP4rev2 or UTF8=ACCEPT is enabled.
That happens after signing in, when the server offers it. Turn it off with
the `prefer_imap4_rev2` setting, or `Protocol\Imap::preferImap4Rev2(false)`.

For local folders use `Storage\Folder\Mbox`, where each file in a directory
tree is a folder, and `Storage\Folder\Maildir`, where each `.Name` maildir in
a [Maildir++](https://en.wikipedia.org/wiki/Maildir#Maildir++) tree is, split
by the delimiter (`.` by default). Folder trees are read once; hidden entries
and symbolic links are skipped, so a folder name can never reach outside the
tree.

```php
use Contenir\Mail\Storage\Folder;

$mail = new Folder\Mbox(['dirname' => '/home/test/mail', 'folder' => 'Archive']);
$mail = new Folder\Maildir(['dirname' => '/home/test/Maildir', 'delim' => '.']);
```

Each folder has a local name (its name in its parent) and a global name (its
full name, which `selectFolder()` takes). A folder that is not selectable
only holds other folders. Iterate a folder for its subfolders, by local name:

```php
use RecursiveIteratorIterator;

$folders = new RecursiveIteratorIterator($mail->getFolders(), RecursiveIteratorIterator::SELF_FIRST);

echo '<select name="folder">';
foreach ($folders as $localName => $folder) {
    printf(
        '<option value="%s"%s>%s%s</option>',
        htmlspecialchars($folder->getGlobalName()),
        $folder->isSelectable() ? '' : ' disabled',
        str_repeat('-', $folders->getDepth()),
        htmlspecialchars($localName),
    );
}
echo '</select>';
```

`getCurrentFolder()` names the selected folder; `selectFolder()` changes it,
by global name or `Folder`. `getFolder()` steps into a subfolder by its local
name:

```php
$folder = $mail->getFolders()->getFolder('Archive')->getFolder('2005');
$mail->selectFolder($folder);
```

### Special-use folders, namespaces and folder status (IMAP)

Servers name their sent, drafts and trash folders differently: `Sent`,
`Sent Items`, `INBOX.Sent`, or a translated name. A server that marks them
(RFC 6154 SPECIAL-USE) tells you which is which. `getSpecialUse()` on an
IMAP folder returns a `Storage\SpecialUse` case (`All`, `Archive`, `Drafts`,
`Flagged`, `Junk`, `Sent` or `Trash`), or null. `getSpecialFolder()` finds
the first folder in the tree with that use:

```php
use Contenir\Mail\Storage\SpecialUse;

$sent = $mail->getSpecialFolder(SpecialUse::Sent);
if (null !== $sent) {
    $mail->appendMessage($message, $sent);
}
```

`getNamespaces()` returns the server's namespaces (RFC 2342), or null when
the server doesn't offer NAMESPACE. Each of `personal`, `otherUsers` and
`shared` is a list of `Protocol\Imap\NamespaceEntry` with a `prefix`, such as
`INBOX.` or `#shared/`, and a `delimiter`, which is null for a flat namespace.
New folders belong under the first personal prefix on servers that have one.

`getFolderStatus()` reads a folder's message count, unseen count, UIDNEXT
and UIDVALIDITY without selecting it. It also reads the folder's size in octets
when the server offers STATUS=SIZE (RFC 8438) or has IMAP4rev2 enabled.
`getFolderSize()` reads only the size, and returns null when the server
can't say:

```php
foreach ($folders as $folder) {
    if ($folder->isSelectable()) {
        $status = $mail->getFolderStatus($folder);
        printf("%s: %d unread, %s octets\n", $folder, $status->unseenCount, $status->size ?? '?');
    }
}
```

## Writing: Maildir and IMAP

`Storage\Writable\Maildir` and `Storage\Imap` implement
`Storage\Writable\WritableInterface`:

```php
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\Writable\Maildir;

$mail = new Maildir(['dirname' => '/home/test/Maildir', 'create' => true]);

$mail->appendMessage($rawMessage);                                  // to the current folder, as Seen
$mail->appendMessage($stream, 'INBOX.Archive', [Flag::Flagged]);    // a stream, without reading it all
$mail->appendMessage($composedMessage);                             // a Contenir\Mail\Message
$mail->copyMessage(3, 'INBOX.Archive');
$mail->moveMessage(3, 'INBOX.Archive');
$mail->setFlags(1, [Flag::Seen, Flag::Answered]);
$mail->createFolder('Projects', 'INBOX');
$mail->renameFolder('INBOX.Projects', 'INBOX.Work');
$mail->removeFolder('INBOX.Work');
```

`appendMessage()`, `copyMessage()` and `moveMessage()` return the UID the
message has in the destination folder when the storage reports one, and null
otherwise. IMAP servers with UIDPLUS (RFC 4315, part of IMAP4rev2) report it,
as Dovecot and Cyrus do: for a move, from the COPYUID that MOVE sends before
it expunges, or from the COPY when there is no MOVE. Maildir has no UIDs and
always returns null.

A UID is only meaningful together with the folder's UIDVALIDITY: when the
server changes that, every UID in the folder may name another message. Store
`getFolderStatus($folder)->uidValidity` with any UID you keep, and find the
message again, for example with `getNumberByUniqueId((string) $uid)`, only
while it is unchanged:

```php
$uid      = $imap->appendMessage($rawMessage, 'Sent');      // 3955, or null without UIDPLUS
$validity = $imap->getFolderStatus('Sent')->uidValidity;    // keep both
```

At the protocol level, `Protocol\Imap::appendReturningUids()`,
`copyReturningUids()` and `moveReturningUids()` return a
`Protocol\Imap\UidMapping` with the folder's UIDVALIDITY and the source and
destination UIDs, or null when the server reports none. They throw
`Protocol\Exception\CommandRefusedException` when the server refuses.
`append()`, `copy()` and `move()` still return a bool.

IMAP literals, such as a message or a non-ASCII password, are sent without
waiting for the server's go-ahead when it offers LITERAL+, or LITERAL- or
IMAP4rev2 for literals up to 4096 bytes (RFC 7888), saving a round trip.

`Storage\Imap` never sends CLOSE, which would expunge the messages flagged
`\Deleted`: selecting another folder, and logging out, leave a folder without
expunging it. To have no folder selected, `Protocol\Imap::unselect()` sends
UNSELECT (RFC 3691) when the server offers it or IMAP4rev2 is enabled.

The Maildir writer follows Maildir delivery: each message is written to a
new file in `tmp/`, opened exclusively so no existing file or symbolic link of
that name is followed, synced to disk, and only then linked into `cur/` (or
`new/` for `appendMessage(..., recent: true)`). It never writes through a
symbolic link: a folder, `tmp/`, `cur/`, `new/` or `maildirsize` that is a
link is refused or left alone. Folder names may not hold `/`, `\`, control
characters, empty parts, or `.` and `..` parts.

Files and directories are created private to their owner (0600 and 0700) by
default; set `file_mode` and `directory_mode` to share them, for example with
a group. The process umask can only take permissions away from these modes.

`Storage\Imap` can also change a single flag, without reading the flags
first or replacing the others. Each call sends one `STORE` command with
`+FLAGS.SILENT` or `-FLAGS.SILENT`:

```php
$mail->addFlags(1, [Flag::Seen]);       // mark Seen; Flagged is untouched
$mail->removeFlags(1, [Flag::Flagged]); // clear Flagged only
```

These two methods are on `Storage\Imap` only, not on `WritableInterface`:
Maildir would need its own semantics, and adding them to the interface would
break other implementers.

The `Recent` flag cannot be set: the storage sets it for messages in `new/`.
Maildir stores the common flags and the keywords `a` to `z`; IMAP stores the
common flags and any keyword atom.

### Quotas

`Storage\Writable\Maildir` supports Maildir++ quotas, off by default. With
checks on, storing is refused while over quota, and the `maildirsize` file is
updated as messages are stored and removed.

```php
$mail->setQuota(true);                              // check against maildirsize
$mail->setQuota(['size' => 10_000_000, 'count' => 1000]);  // or against your own

printf("You are %sover quota\n", $mail->checkQuota() ? '' : 'not ');

$quota = $mail->checkQuota(detailedResponse: true);
printf(
    "You have %d of %d messages and use %d of %d bytes\n",
    $quota['count'],
    $quota['quota']['count'] ?? 0,
    $quota['size'],
    $quota['quota']['size'] ?? 0,
);
```

`getQuota()` returns the setting, and `getQuota(fromStorage: true)` the quota
`maildirsize` defines. Anyone who can deliver to the maildir can write
`maildirsize`, so it is read with care: only the first 5 KB, only valid
fields, and with totals kept in range; a larger or malformed file is counted
afresh.

## Extending protocol classes

Remote storages use a storage class and a protocol class
(`Contenir\Mail\Protocol\Imap` or `Pop3`), which turns protocol commands and
responses into PHP. To use your own protocol class, pass it to the storage:
either connected and logged in, or as the second argument, to be connected
with the settings:

```php
use Contenir\Mail\Storage\Pop3;

$protocol = new KnockingPop3(); // extends Contenir\Mail\Protocol\Pop3
$protocol->knock([1101, 1105, 1111]);

$mail = new Pop3(['host' => 'pop.example.com', 'user' => 'test', 'password' => $password], $protocol);
```

## Migrating from laminas-mail

The [migration guide](migrating.md) maps the laminas-mail storage API to this
one. Check its silent changes first: message flags, decoded part content and
IMAP folder names all behave differently without an error.
