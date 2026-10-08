# Messages

`Contenir\Mail\Message` encapsulates a single email message as described in
[RFC 5322](https://www.rfc-editor.org/rfc/rfc5322). You build it step by step,
setting addresses, a subject, other headers and content.

Text, HTML, attachments and inline images are added with `setText()`,
`setHtml()`, `attach()` and `embed()`, and arranged into a MIME body when the
message is written.

The `Message` class is mutable, but everything it holds is an immutable value:
its headers are a `Contenir\Mail\Headers` collection, each header is a value
object from `Contenir\Mail\Header`, and addresses are `Contenir\Mail\Address`
and `Contenir\Mail\AddressList` instances. A cloned message can therefore be
changed without affecting the original.

A `Message` is not capable of sending or storing itself; for those purposes,
you will need to use, respectively, a [Transport adapter](../transport/intro.md)
or a [Storage adapter](../read.md).

## Quick Start

Creating a `Message` by instantiating it:

```php
use Contenir\Mail\Message;

$message = new Message();
```

Once you have your `Message` instance, you can start adding content or headers.
Let's set who the mail is from, who it's addressed to, a subject, and some
content:

```php
$message->addFrom('matthew@example.org', 'Matthew Somelli');
$message->addTo('foobar@example.com');
$message->setSubject('Sending an email from Contenir\Mail!');
$message->setText('This is the message body.');
```

You can also add recipients to carbon-copy ("Cc:") or blind carbon-copy
("Bcc:").

```php
$message->addCc('ralph@example.org');
$message->addBcc('enrico@example.org');
```

If you want to specify an alternate address to which replies may be sent, that
can be done, too.

```php
$message->addReplyTo('matthew@example.com', 'Matthew');
```

Every address method accepts several forms. A single e-mail address can be
given with an optional display name as the second argument, or as one string
such as `'Ralph <ralph@example.org>'`. You can also pass an `Address`, an
`AddressList`, or an array (or any iterable) whose entries are `Address`
instances, address strings, or `email => name` pairs. The display name argument
is only accepted with a single e-mail address string; combining it with any
other form throws a `Contenir\Mail\Exception\InvalidArgumentException`.

```php
use Contenir\Mail\Address;
use Contenir\Mail\AddressList;

$message->addTo([
    'alice@example.com' => 'Alice',
    'Bob <bob@example.com>',
    new Address('carol@example.com', 'Carol'),
]);

$message->addCc(new AddressList(
    new Address('dave@example.com'),
    new Address('erin@example.com', 'Erin'),
));
```

The `set*()` methods replace the addresses in that header, while the `add*()`
methods append to them. An address that is already present (compared without
regard to case) is not added twice.

Interestingly, RFC 5322 allows for multiple "From:" addresses. When you do this,
a "Sender:" header naming the single mailbox responsible for sending the
message is required. The `Message` class allows for this.

```php
/*
 * Mail headers created:
 * From: Ralph Nader <ralph@example.org>,
 *  Enrico Volante <enrico@example.org>
 * Sender: Matthew Sommeli <matthew@example.org>
 */
$message->setFrom('ralph@example.org', 'Ralph Nader');
$message->addFrom('enrico@example.org', 'Enrico Volante');
$message->setSender('matthew@example.org', 'Matthew Sommeli');
```

Headers take care of their own encoding. Plain ASCII values are written as
they are, and anything else (an accented name, a subject in Japanese) is
encoded as RFC 2047 UTF-8 automatically. Text and HTML bodies are UTF-8 by
default; see [Character Sets](character-sets.md) for other character sets.

If you wish to set other headers, you can do that as well. `addHeader()` adds a
header alongside any others of the same name, while `setHeader()` replaces them.
`removeHeader()` removes every header with the given name. Headers RFC 5322 allows
only once (Date, From, Sender, Reply-To, To, Cc, Bcc, Message-ID, In-Reply-To,
References and Subject) can only be replaced: adding a second one throws.

A new message gets a `Message-ID` on its sender's domain the first time its
headers are read, and a message with several From addresses gets the first as
its `Sender` unless one is set. Set your own `Message-ID` with `setHeader()`, or
remove it with `removeHeader('Message-ID')` to send none.

```php
use Contenir\Mail\Header\GenericHeader;

/*
 * Mail headers created:
 * X-API-Key: FOO-BAR-BAZ-BAT
 * X-Mailer: contenir-mail
 */
$message->addHeader(new GenericHeader('X-API-Key', 'FOO-BAR-BAZ-BAT'));
$message->setHeader(new GenericHeader('X-Mailer', 'contenir-mail'));
```

To send HTML, give it alongside the text. Mail clients show whichever they
prefer:

```php
use Contenir\Mail\Mime\Attachment;

$message->setHtml('<p>This is the <strong>message</strong> body.</p>');
$message->attach(Attachment::fromPath('/var/reports/q3.pdf'));
```

The `MIME-Version` and `Content-Type` headers are added for you. Read
[Adding Attachments](attachments.md) for attachments, inline images and custom
MIME structures.

If you want a string representation of your email, you can get that:

```php
echo $message->toString();
```

Finally, you can fully introspect the message, including getting all addresses
of recipients and senders, all headers, and the message body.

```php
// Headers, including those set through the address and subject methods
foreach ($message->getHeaders() as $header) {
    echo $header->toString(), "\n";
    // or grab values: $header->getFieldName(), $header->getFieldValue()
}

// A single header, or null when the message has none
$mailer = $message->getHeaders()->get('X-Mailer')?->getFieldValue();

// Every header with a given name, as a list
$keys = $message->getHeaders()->all('X-API-Key');

// The same works for getTo(), getCc(), getBcc() and getReplyTo()
foreach ($message->getFrom() as $address) {
    printf("%s: %s\n", $address->getEmail(), $address->getName());
}

// Sender
$address = $message->getSender();
if (null !== $address) {
    printf("%s: %s\n", $address->getEmail(), $address->getName());
}

// Subject
echo "Subject: ", $message->getSubject(), "\n";

// Message body:
$body = $message->getBody();  // a string or Stringable set with setBody(), a MIME part, or null
echo $message->getBodyText(); // body as it will be sent
```

The address getters return an immutable `AddressList`. Changing a list gives
you a new one, which you then pass back to the message:

```php
$to = $message->getTo();

$to->has('alice@example.com');       // true; the comparison ignores case
$to->get('alice@example.com');       // the Address, or null
$to->first();                        // the first Address, or null
$to->isEmpty();                      // false
count($to);                          // 4

$message->setTo($to->without('alice@example.com'));
$message->setTo($to->with('frank@example.com', 'Frank'));
```

Once your message is shaped to your liking, pass it to a
[mail transport](../transport/intro.md) in order to send it!

```php
$transport->send($message);
```

## Configuration Options

The constructor takes optional starting headers and a PSR-20 clock, which
supplies the `Date` header. Pass a fixed clock to get repeatable output in
tests:

```php
new Message(clock: $clock); // Psr\Clock\ClockInterface
```

## Available Methods

Every method that changes the message returns the message itself, so calls can
be chained.

In the signatures below, `$addresses` accepts
`Address|AddressList|string|iterable<int|string, Address|string|null>`:

- a single e-mail address string (`'ralph@example.org'`), optionally with a
  display name as the `$name` argument;
- an address string with a display name (`'Ralph <ralph@example.org>'`);
- an `Address` or an `AddressList`;
- an iterable whose entries are `Address` instances, address strings, or
  `email => name` pairs.

`$name` may only be given when `$addresses` is a single e-mail address string.

### isValid

```php
isValid() : bool
```

Messages without a `From` address are invalid, per RFC 5322.

### setHeaders

```php
setHeaders(Contenir\Mail\Headers $headers) : self
```

Replace the whole header collection.

### getHeaders

```php
getHeaders() : Contenir\Mail\Headers
```

Return the header collection. When the body is a MIME part, `MIME-Version` and
the body's content headers (`Content-Type`, `Content-Transfer-Encoding`, ...)
are included. `Headers` is immutable: changing a header through the message
replaces the collection, so a `Headers` instance you fetched earlier keeps its
old contents.

### setHeader

```php
setHeader(Contenir\Mail\Header\HeaderInterface $header) : self
```

Set a header, replacing any headers with the same name.

### addHeader

```php
addHeader(Contenir\Mail\Header\HeaderInterface $header) : self
```

Add a header, keeping any headers with the same name. Throws a
`Contenir\Mail\Exception\InvalidArgumentException` for a second header that RFC 5322
allows only once.

### removeHeader

```php
removeHeader(string $name) : self
```

Remove every header with the given name. Header names are compared without
regard to case.

### setFrom

```php
setFrom($addresses, ?string $name = null) : self
```

Set (overwrite) `From` addresses.

### addFrom

```php
addFrom($addresses, ?string $name = null) : self
```

Add one or more `From` addresses.

### getFrom

```php
getFrom() : Contenir\Mail\AddressList
```

Retrieve the list of `From` senders. The list is empty when no `From` header
has been set.

### setTo

```php
setTo($addresses, ?string $name = null) : self
```

Set (overwrite) the `To` recipients.

### addTo

```php
addTo($addresses, ?string $name = null) : self
```

Add one or more addresses to the `To` recipients.

### getTo

```php
getTo() : Contenir\Mail\AddressList
```

Retrieve the list of `To` recipients.

### setCc

```php
setCc($addresses, ?string $name = null) : self
```

Set (overwrite) the `Cc` recipients.

### addCc

```php
addCc($addresses, ?string $name = null) : self
```

Add one or more addresses to the `Cc` recipients.

### getCc

```php
getCc() : Contenir\Mail\AddressList
```

Retrieve the list of `Cc` recipients.

### setBcc

```php
setBcc($addresses, ?string $name = null) : self
```

Set (overwrite) the `Bcc` recipients.

### addBcc

```php
addBcc($addresses, ?string $name = null) : self
```

Add one or more addresses to the `Bcc` recipients.

### getBcc

```php
getBcc() : Contenir\Mail\AddressList
```

Retrieve the list of `Bcc` recipients.

### setReplyTo

```php
setReplyTo($addresses, ?string $name = null) : self
```

Set (overwrite) the `Reply-To` addresses.

### addReplyTo

```php
addReplyTo($addresses, ?string $name = null) : self
```

Add one or more addresses to the `Reply-To` addresses.

### getReplyTo

```php
getReplyTo() : Contenir\Mail\AddressList
```

Retrieve the list of `Reply-To` addresses.

### setSender

```php
setSender(Contenir\Mail\Address|string $emailOrAddress, ?string $name = null) : self
```

Set the `Sender` header.

### getSender

```php
getSender() : ?Contenir\Mail\Address
```

Retrieve the sender address, if any.

### setSubject

```php
setSubject(string $subject) : self
```

Set the message subject header value. The subject must be US-ASCII or UTF-8
text.

### getSubject

```php
getSubject() : ?string
```

Get the decoded message subject, or `null` when none is set.

### setText

```php
setText(string $text, string $charset = 'UTF-8') : self
```

Set the plain-text body, quoted-printable encoded.

### setHtml

```php
setHtml(string $html, string $charset = 'UTF-8') : self
```

Set the HTML body, quoted-printable encoded. With `setText()`, the two become
`multipart/alternative`.

### attach

```php
attach(Contenir\Mail\Mime\Part $attachment) : self
```

Add an attachment, such as one built with `Mime\Attachment::fromPath()`.

### embed

```php
embed(Contenir\Mail\Mime\Part $resource) : self
```

Add a resource the HTML refers to as `cid:…`, such as one built with
`Mime\Attachment::inline()`. Throws a
`Contenir\Mail\Mime\Exception\InvalidArgumentException` when the part has no
Content-ID.

### setBody

```php
setBody(string|Stringable|Contenir\Mail\Mime\PartInterface|null $body) : self
```

Set the body directly, in place of anything given to `setText()`, `setHtml()`,
`attach()` and `embed()`. A string is sent as it is; a MIME part or multipart
adds its content headers to the message. `null` returns to the builders.

### getBody

```php
getBody() : string|Stringable|Contenir\Mail\Mime\PartInterface|null
```

Return the body given to `setBody()`, or else the MIME tree built from the
builders, or `null` when there is no body.

### getBodyText

```php
getBodyText() : string
```

Get the body as it will be sent, with a multipart body's parts and boundaries
written out.

### toString

```php
toString() : string
```

Serialize the headers and body to a string.

### fromString

```php
static fromString(string $rawMessage) : Contenir\Mail\Message
```

Parse a raw message into a `Message` with its headers and body text.

## Addresses

`Contenir\Mail\Address` is an immutable value holding an e-mail address, an
optional display name and an optional comment. The address is validated when
the object is created; an invalid address, or one containing a line break,
throws a `Contenir\Mail\Exception\InvalidArgumentException`.

```php
use Contenir\Mail\Address;

$address = new Address('ralph@example.org', 'Ralph Nader');
$address = Address::fromString('Ralph Nader <ralph@example.org>');

$address->getEmail();   // 'ralph@example.org'
$address->getName();    // 'Ralph Nader'
$address->getComment(); // null
$address->toString();   // 'Ralph Nader <ralph@example.org>'
```

`Contenir\Mail\AddressList` is an immutable, countable and iterable list of
`Address` instances, keyed by e-mail address without regard to case.

Method | Description
------ | -----------
`new AddressList(Address ...$addresses)` | Create a list.
`AddressList::fromIterable(iterable $addresses)` | Create a list from `Address` instances, address strings, or `email => name` pairs.
`with(Address\|string $emailOrAddress, ?string $name = null)` | Return a new list with the address added.
`withList(AddressList $addressList)` | Return a new list with all addresses of another list added.
`without(string $email)` | Return a new list without the address.
`has(string $email)` | Whether the list contains the address.
`get(string $email)` | Return the `Address`, or `null`.
`first()` | Return the first `Address`, or `null`.
`isEmpty()` | Whether the list is empty.
`toArray()` | Return the addresses as a list.

### Groups

An address list can also name groups of addresses (RFC 5322, section 3.4).
`Contenir\Mail\AddressGroup` holds a group's name and its `AddressList`; every
address method accepts one. A group may be empty, which is how a message sent
only to Bcc recipients usually fills its To header:

```php
use Contenir\Mail\AddressGroup;
use Contenir\Mail\AddressList;

$message->setTo(new AddressGroup('undisclosed-recipients')); // To: undisclosed-recipients:;
$message->addCc(new AddressGroup('Team', AddressList::fromIterable(['jo@example.org', 'sam@example.org'])));
```

`getTo()` and the other address getters return every address, group members
included, since they are all recipients. The groups themselves, with their
names, are on the header: `$message->getHeaders()->get('To')?->getGroups()`.
Groups read from stored mail keep their names.

## Headers

`Contenir\Mail\Headers` is an immutable, countable and iterable collection of
header objects. Methods that change it return a new collection. Header names
are compared without regard to case.

Method | Description
------ | -----------
`new Headers(HeaderInterface ...$headers)` | Create a collection.
`Headers::fromString(string $string)` | Parse a block of header lines.
`Headers::fromIterable(iterable $headers)` | Create a collection from `HeaderInterface` instances, header lines, `name => value` pairs, or `[name, value]` pairs.
`with(HeaderInterface $header)` | Return a new collection with the header replacing any of the same name.
`withAdded(HeaderInterface $header)` | Return a new collection with the header added.
`without(string $name)` | Return a new collection without any header of that name.
`get(string $name)` | Return the first header with that name, or `null`.
`all(string $name)` | Return every header with that name, as a list.
`has(string $name)` | Whether a header with that name exists.
`toString()` | Serialize the headers, one line per header.
`toArray()` | Return the decoded values, keyed by header name.
`toList()` | Return the header objects as a list.

Each header class in `Contenir\Mail\Header` is created through its constructor
and has no setters:

```php
use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Header\ContentDisposition;
use Contenir\Mail\Header\ContentTransferEncoding;
use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Header\Date;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Header\MessageId;
use Contenir\Mail\Header\Subject;
use Contenir\Mail\Header\To;
use Contenir\Mail\Mime\TransferEncoding;

new Subject('Quarterly report');
new ContentType('text/plain', ['charset' => 'UTF-8']);
new ContentDisposition('attachment', ['filename' => 'report.pdf']);
new ContentTransferEncoding(TransferEncoding::Base64);
new MessageId('report-2026-q3@example.com');
MessageId::generate();
new Date(new DateTimeImmutable());
new To(new AddressList(new Address('matthew@example.org')));
new GenericHeader('X-Mailer', 'contenir-mail');
```

Every header provides the same methods to read it:

Method | Description
------ | -----------
`getFieldName()` | The header name, such as `Subject`.
`getFieldValue()` | The decoded value, as you would display it.
`getEncodedFieldValue()` | The value as it is written to the message, encoded where needed.
`toString()` | The full header line in its encoded form.

```php
$subject = new Subject('Café menu');

$subject->getFieldValue();        // 'Café menu'
$subject->getEncodedFieldValue(); // '=?UTF-8?Q?Caf=C3=A9=20menu?='
$subject->toString();             // 'Subject: =?UTF-8?Q?Caf=C3=A9=20menu?='
```

Some headers offer `with*()` methods that return a changed copy, for example
`ContentType::withType()` and `ContentType::withParameter()`. To change a header
on a message, create the new header and pass it to `setHeader()`.
