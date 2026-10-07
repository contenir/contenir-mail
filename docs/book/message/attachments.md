# Adding Attachments

`Message` builds MIME bodies for you. Give it the text, the HTML, any images
the HTML refers to, and any files to attach, and it arranges them into the
right multipart structure when the message is written.

```php
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Attachment;

$message = new Message();
$message->setFrom('reports@example.org');
$message->addTo('ralph@example.org');
$message->setSubject('Quarterly report');

$message->setText('The report is attached.');
$message->setHtml('<p>The report is attached.</p><img src="cid:logo">');
$message->embed(Attachment::inline($logoPng, id: 'logo', type: 'image/png'));
$message->attach(Attachment::fromPath('/var/reports/q3.pdf'));
```

The body is assembled as:

```text
multipart/mixed
├── multipart/alternative
│   ├── text/plain
│   └── multipart/related
│       ├── text/html
│       └── image/png          (Content-ID: <logo>)
└── application/pdf            (attachment; filename="q3.pdf")
```

Only the levels you need are used:

You call | The body is
--- | ---
`setText()` | `text/plain`
`setHtml()` | `text/html`
`setText()` and `setHtml()` | `multipart/alternative`, text first
`setHtml()` and `embed()` | `multipart/related`, HTML first
`attach()` with any of the above | `multipart/mixed`, content first and attachments after it

`getHeaders()` and `toString()` add `MIME-Version: 1.0` and the body's
`Content-Type` (with its boundary) and `Content-Transfer-Encoding`, so you
never set them yourself. Boundaries are generated once and kept until you
change the body again.

`setText()` and `setHtml()` replace any earlier text or HTML. `attach()` and
`embed()` add to the parts already given.

## Building attachments

`Contenir\Mail\Mime\Attachment` builds the parts:

```php
use Contenir\Mail\Mime\Attachment;

// A file on disk. It is read only when the message is written, in chunks.
// The type is detected from its contents when ext-fileinfo is available.
Attachment::fromPath('/var/reports/q3.pdf');
Attachment::fromPath('/tmp/upload-81f2', filename: 'invoice.pdf', type: 'application/pdf');

// Content you already have in memory
Attachment::fromString($csv, filename: 'export.csv', type: 'text/csv');

// A resource the HTML refers to as "cid:logo"
Attachment::inline($logoPng, id: 'logo', type: 'image/png', filename: 'logo.png');
```

Each returns an immutable `Contenir\Mail\Mime\Part`, base64 encoded. For
anything else, such as a different transfer encoding or a `Content-Description`,
create the `Part` yourself; see [Parts](../mime/part.md).

```php
use Contenir\Mail\Mime\Disposition;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Mime\TransferEncoding;

$message->attach(new Part(
    $calendar,
    type: 'text/calendar',
    encoding: TransferEncoding::QuotedPrintable,
    charset: 'UTF-8',
    disposition: Disposition::Attachment,
    filename: 'meeting.ics',
));
```

## Building the structure yourself

When you need a structure the builders do not make, compose it from
`Contenir\Mail\Mime\Part` and `Contenir\Mail\Mime\Multipart` and pass the root
to `setBody()`. A body set this way takes the place of anything given to the
builders; `setBody(null)` returns to them.

```php
use Contenir\Mail\Mime\Multipart;
use Contenir\Mail\Mime\MultipartType;
use Contenir\Mail\Mime\Part;

$message->setBody(new Multipart(MultipartType::Alternative, [
    Part::text($text),
    Part::html($html),
    new Part($amp, type: 'text/x-amp-html', charset: 'UTF-8'),
]));
```

A boundary is generated for each multipart. Pass one as the third argument when
you need fixed output, for example in tests:

```php
new Multipart(MultipartType::Mixed, $parts, boundary: 'report-boundary');
```

See [Multiparts](../mime/multipart.md) for the rules a boundary must follow.
