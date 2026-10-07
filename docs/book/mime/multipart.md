# Contenir\\Mail\\Mime\\Multipart

`Contenir\Mail\Mime\Multipart` groups parts, and other multiparts, under one
`multipart/*` content type. It is an immutable value.

```php
use Contenir\Mail\Mime\Multipart;
use Contenir\Mail\Mime\MultipartType;
use Contenir\Mail\Mime\Part;

$alternative = new Multipart(MultipartType::Alternative, [
    Part::text($text),
    Part::html($html),
]);
```

`MultipartType` names the relationship between the parts:

Type | Meaning
---- | -------
`Mixed` | Independent parts, such as content followed by attachments.
`Alternative` | The same content in several forms, simplest first; the client shows the last one it supports.
`Related` | A root part, such as HTML, followed by the resources it refers to.

A multipart needs at least one part; an empty list throws a
`Contenir\Mail\Mime\Exception\InvalidArgumentException`.

## Boundaries

Each multipart has a boundary that separates its parts. One is generated when
none is given: `=_` followed by 32 hexadecimal characters, which cannot occur
in base64 or quoted-printable content.

Pass a boundary to get fixed output:

```php
new Multipart(MultipartType::Mixed, $parts, boundary: 'report-boundary');
```

A boundary must follow RFC 2046: 1 to 70 characters from letters, digits,
space and `'()+_,-./:=?`, not ending with a space. Any other boundary throws a
`Contenir\Mail\Mime\Exception\InvalidArgumentException`.

## Writing

`getHeaders()` returns the `Content-Type` with its boundary:

```text
Content-Type: multipart/alternative;
 boundary="report-boundary"
```

`Contenir\Mail\Mime\PartWriter::body()` writes the parts:

```text
--report-boundary
Content-Type: text/plain;
 charset="UTF-8"
Content-Transfer-Encoding: quoted-printable

Hello
--report-boundary
Content-Type: text/html;
 charset="UTF-8"
Content-Transfer-Encoding: quoted-printable

<p>Hello</p>
--report-boundary--
```

When a multipart is a message's body, `Message` writes a short preamble for
mail clients that do not understand MIME before the first boundary.

## Available methods

- `getType()`: The `MultipartType`.
- `getBoundary()`: The boundary.
- `getParts()`: The parts, in order.
- `getHeaders()`: The `Content-Type` header, as a `Contenir\Mail\Headers` collection.
- `isMultipart()`: Always `true`.
- `getContent()`, `getEncodedContent()`: Always empty; write the parts with
  `PartWriter::body()`.
