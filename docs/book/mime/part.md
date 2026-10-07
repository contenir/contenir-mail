# Contenir\\Mail\\Mime\\Part

`Contenir\Mail\Mime\Part` is a single leaf of a MIME body: text, HTML, an
attachment or an inline resource. It is an immutable value; everything about it
is given to its constructor, usually by name.

```php
use Contenir\Mail\Mime\Disposition;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Mime\TransferEncoding;

$part = new Part(
    $content,                                  // string, or a readable stream
    type: 'application/pdf',                   // default application/octet-stream
    encoding: TransferEncoding::Base64,        // the default
    charset: null,                             // for text types
    disposition: Disposition::Attachment,
    filename: 'report.pdf',
    id: null,                                  // Content-ID, without < >
    description: 'Third quarter',
    location: null,
    language: 'en',
);
```

`Part::text($text, $charset = 'UTF-8')` and `Part::html($html, $charset = 'UTF-8')`
create quoted-printable text parts. `Contenir\Mail\Mime\Attachment` creates
attachment and inline parts; see [Adding Attachments](../message/attachments.md).

## Streams

The content can be a readable stream instead of a string, for large files.
A stream is read from its start each time the part is written, and base64
content is encoded as it is read rather than loaded into memory first.
`Attachment::fromPath()` opens the file this way.

## Headers

`getHeaders()` returns the part's content headers, built from its fields:

```text
Content-Type: application/pdf
Content-Transfer-Encoding: base64
Content-Disposition: attachment; filename="report.pdf"
Content-Description: Third quarter
Content-Language: en
```

A `charset` is added to `Content-Type`, an `id` becomes `Content-ID: <id>`, and
a long or non-ASCII filename is encoded under RFC 2231. Fields left as `null`
add no header.

## Available methods

- `getType()`, `getTransferEncoding()`, `getCharset()`, `getDisposition()`,
  `getFilename()`, `getId()`: The fields given to the constructor.
- `getHeaders()`: The content headers, as a `Contenir\Mail\Headers` collection.
- `getContent()`: The content as given, unencoded.
- `getEncodedContent()`: The content after its transfer encoding, with CRLF
  line endings.
- `isMultipart()`: Always `false`.
- `getParts()`: Always an empty list.
