# Introduction

`Contenir\Mail\Mime\Mime` is a support class for handling multipart
[MIME](https://en.wikipedia.org/wiki/MIME) messages;
[laminas-mail](https://github.com/laminas/laminas-mail) relies on it for both
parsing and creating multipart messages. [`Contenir\Mail\Mime\Message`](message.md) can
also be consumed by applications requiring general MIME support.

## Static Methods and Constants

`Contenir\Mail\Mime\Mime` provides a set of static helper methods to work with MIME:

- `Contenir\Mail\Mime\Mime::isPrintable()`: Returns `TRUE` if the given string contains
  no unprintable characters, `FALSE` otherwise.
- `Contenir\Mail\Mime\Mime::encode()`: Encodes a string with the specified encoding.
- `Contenir\Mail\Mime\Mime::encodeBase64()`: Encodes a string into base64 encoding.
- `Contenir\Mail\Mime\Mime::encodeQuotedPrintable()`: Encodes a string with the
  quoted-printable mechanism.
- `Contenir\Mail\Mime\Mime::encodeBase64Header()`: Encodes a string into base64 encoding
  for Mail Headers.
- `Contenir\Mail\Mime\Mime::encodeQuotedPrintableHeader()`: Encodes a string with the
  quoted-printable mechanism for Mail Headers.
- `Contenir\Mail\Mime\Mime::mimeDetectCharset()`: detects if a string is encoded as
  ASCII, Base64, or quoted-printable.

`Contenir\Mail\Mime\Mime` defines a set of constants commonly used with MIME messages:

- `Contenir\Mail\Mime\Mime::TYPE_ENRICHED`: 'text/enriched'
- `Contenir\Mail\Mime\Mime::TYPE_HTML`: 'text/html'
- `Contenir\Mail\Mime\Mime::TYPE_OCTETSTREAM`: 'application/octet-stream'
- `Contenir\Mail\Mime\Mime::TYPE_TEXT`: 'text/plain'
- `Contenir\Mail\Mime\Mime::TYPE_XML`: 'text/xml'
- `Contenir\Mail\Mime\Mime::ENCODING_BASE64`: 'base64'
- `Contenir\Mail\Mime\Mime::ENCODING_7BIT`: '7bit'
- `Contenir\Mail\Mime\Mime::ENCODING_8BIT`: '8bit'
- `Contenir\Mail\Mime\Mime::ENCODING_QUOTEDPRINTABLE`: 'quoted-printable'
- `Contenir\Mail\Mime\Mime::DISPOSITION_ATTACHMENT`: 'attachment'
- `Contenir\Mail\Mime\Mime::DISPOSITION_INLINE`: 'inline'
- `Contenir\Mail\Mime\Mime::MESSAGE_DELIVERY_STATUS`: 'message/delivery-status'
- `Contenir\Mail\Mime\Mime::MULTIPART_ALTERNATIVE`: 'multipart/alternative'
- `Contenir\Mail\Mime\Mime::MULTIPART_MIXED`: 'multipart/mixed'
- `Contenir\Mail\Mime\Mime::MULTIPART_RELATED`: 'multipart/related'
- `Contenir\Mail\Mime\Mime::MULTIPART_RELATIVE`: 'multipart/relative'
- `Contenir\Mail\Mime\Mime::MULTIPART_REPORT`: 'multipart/report'
- `Contenir\Mail\Mime\Mime::MULTIPART_RFC822`: 'multipart/rfc822'

## Instantiating Contenir\\Mail\\Mime

When instantiating a `Contenir\Mail\Mime\Mime` object, a MIME boundary is stored that is
used for all instance calls. If the constructor is called with a string
parameter, this value is used as the MIME boundary; if not, a random MIME
boundary is generated.

A `Contenir\Mail\Mime\Mime` object has the following methods:

- `boundary()`: Returns the MIME boundary string.
- `boundaryLine()`: Returns the complete MIME boundary line.
- `mimeEnd()`: Returns the complete MIME end boundary line.
