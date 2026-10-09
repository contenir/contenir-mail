# Introduction

`Contenir\Mail\Mime` composes and encodes [MIME](https://en.wikipedia.org/wiki/MIME)
bodies. `Message` uses it to build bodies from text, HTML and attachments, and
you can use it directly to build any other structure.

A MIME body is a tree. Every node implements `Contenir\Mail\Mime\PartInterface`:

- [`Part`](part.md) is a leaf: text, HTML, an attachment or an inline image.
- [`Multipart`](multipart.md) groups other nodes as `multipart/mixed`,
  `multipart/alternative` or `multipart/related`.

Both are immutable values. Nothing is encoded until the body is written, and
content is never stored encoded.

```php
use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Mime\Multipart;
use Contenir\Mail\Mime\MultipartType;
use Contenir\Mail\Mime\Part;

$body = new Multipart(MultipartType::Mixed, [
    new Multipart(MultipartType::Alternative, [
        Part::text('Hello'),
        Part::html('<p>Hello</p>'),
    ]),
    Attachment::fromPath('/var/reports/q3.pdf'),
]);

$message->setBody($body);
```

`PartInterface` is also what a parser can return for a received message, so the
same tree can be read as well as written.

Method | Description
------ | -----------
`getHeaders()` | The content headers: `Content-Type`, `Content-Transfer-Encoding`, and for a leaf any of `Content-ID`, `Content-Disposition`, `Content-Description`, `Content-Location` and `Content-Language`.
`isMultipart()` | Whether this node holds other parts.
`getParts()` | The child parts; empty for a leaf.
`getContent()` | The decoded content; empty for a multipart.
`getEncodedContent()` | The content after its transfer encoding; empty for a multipart.

`Contenir\Mail\Mime\PartWriter::body($part)` writes a tree as it is sent: a
leaf's encoded content, or each child of a multipart between its boundaries.

## Enums

- `TransferEncoding`: `SevenBit`, `EightBit`, `Binary`, `QuotedPrintable`, `Base64`.
- `Disposition`: `Inline`, `Attachment`.
- `MultipartType`: `Mixed`, `Alternative`, `Related`.

## Contenir\\Mail\\Mime\\Mime

`Mime` holds static encoding helpers and constants:

- `isPrintable()`: Whether the string has no unprintable characters.
- `encode(string $str, TransferEncoding $encoding, string $eol = "\n")`:
  Encode a string with the given transfer encoding.
- `encodeBase64()`: Encode a string as base64 in lines.
- `encodeQuotedPrintable()`: Encode a string as quoted-printable.
- `encodeBase64Header()`: Encode a string as a base64 encoded word for a header.
- `encodeQuotedPrintableHeader()`: Encode a string as a quoted-printable
  encoded word for a header.
- `mimeDetectCharset()`: Detect whether an encoded word is base64 or
  quoted-printable.

Its constants name common types (`TYPE_TEXT`, `TYPE_HTML`, `TYPE_OCTETSTREAM`,
`TYPE_ENRICHED`, `TYPE_XML`, `MULTIPART_REPORT`, `MESSAGE_RFC822`,
`MESSAGE_DELIVERY_STATUS`).

The constants for encodings (`ENCODING_*`), dispositions (`DISPOSITION_*`)
and the other multipart types (`MULTIPART_MIXED`, `MULTIPART_ALTERNATIVE`,
`MULTIPART_RELATED`) are deprecated in favour of the `TransferEncoding`,
`Disposition` and `MultipartType` enums, such as
`TransferEncoding::Base64->value` and `MultipartType::Mixed->contentType()`.
`MULTIPART_RELATIVE` is deprecated too: it names `multipart/relative`, which
no RFC defines; RFC 2387 defines `multipart/related`. PHP 8.4 and later
report their use.

## Contenir\\Mail\\Mime\\Decode

`Decode` splits raw MIME text when reading mail: `splitMime()`,
`splitMessageStruct()`, `splitMessage()`, `splitContentType()`,
`splitHeaderField()` and `decodeQuotedPrintable()`. The storage classes use it
to read messages.
