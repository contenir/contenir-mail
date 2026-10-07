# Character Sets

Headers and body handle character sets in different ways.

- **Headers encode themselves.** Plain ASCII values are written as they are.
  Any other text, such as an accented display name or a subject in Japanese, is
  encoded as an RFC 2047 UTF-8 "encoded word" automatically. You do not need to
  configure anything for this.
- **The body's character set is declared on the body.** In a MIME message, set
  the character set on each text part. For a plain string body, add a
  `Content-Type` header yourself. `Message::setEncoding()` records the body's
  character set, which you can read back with `getEncoding()`, but it does not
  add or change any header.

> ## Header text must be UTF-8
>
> Pass header values (subjects, display names, other header text) as UTF-8
> strings. Text in any other encoding is rejected with an exception
> implementing `Contenir\Mail\Exception\ExceptionInterface`. Convert text from
> other encodings first, for example with `mb_convert_encoding($text, 'UTF-8',
> 'ISO-8859-1')`.

> ## Only in text format
>
> Character sets are only applicable for message parts in text format.

## Example

The following example sends a message in Japanese, with a UTF-8 body.

```php
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Message as MimeMessage;
use Contenir\Mail\Mime\Mime;
use Contenir\Mail\Mime\Part as MimePart;

$mail = new Message();

// Header text is given as UTF-8; it is encoded when the message is written.
$mail->setFrom('somebody@example.com', '山田太郎');
$mail->addTo('somebody_else@example.com', '鈴木花子');
$mail->setSubject('会議のお知らせ');

// The body declares its own character set on the MIME part.
$part           = new MimePart('明日の会議は十時からです。');
$part->type     = Mime::TYPE_TEXT;
$part->charset  = 'UTF-8';
$part->encoding = Mime::ENCODING_QUOTEDPRINTABLE;

$body = new MimeMessage();
$body->addPart($part);
$mail->setBody($body);
$mail->setEncoding('UTF-8');

echo $mail->toString();
```

The message is written as follows (the `Date` header is left out here):

```text
From: =?UTF-8?Q?=E5=B1=B1=E7=94=B0=E5=A4=AA=E9=83=8E?= <somebody@example.com>
To: =?UTF-8?Q?=E9=88=B4=E6=9C=A8=E8=8A=B1=E5=AD=90?= <somebody_else@example.com>
Subject: =?UTF-8?Q?=E4=BC=9A=E8=AD=B0=E3=81=AE=E3=81=8A=E7=9F=A5=E3=82=89=E3=81=9B?=
MIME-Version: 1.0
Content-Type: text/plain;
 charset="UTF-8"
Content-Transfer-Encoding: quoted-printable

=E6=98=8E=E6=97=A5=E3=81=AE=E4=BC=9A=E8=AD=B0=E3=81=AF=E5=8D=81=E6=99=82=
=E3=81=8B=E3=82=89=E3=81=A7=E3=81=99=E3=80=82
```

Reading the subject back from the message gives the decoded text:

```php
echo $mail->getSubject(); // 会議のお知らせ
```

Each header also exposes both forms. `getFieldValue()` returns the decoded
value, while `getEncodedFieldValue()` and `toString()` return the value as it is
written to the message:

```php
use Contenir\Mail\Header\Subject;

$subject = new Subject('Café menu');

echo $subject->getFieldValue();        // Café menu
echo $subject->getEncodedFieldValue(); // =?UTF-8?Q?Caf=C3=A9=20menu?=
echo $subject->toString();             // Subject: =?UTF-8?Q?Caf=C3=A9=20menu?=
```

## Using another character set for the body

The body can use any character set your recipients' mail clients support. Convert
the text, then name the character set on the part. Headers are still written
as UTF-8 encoded words, which mail clients decode independently of the body.

```php
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Message as MimeMessage;
use Contenir\Mail\Mime\Mime;
use Contenir\Mail\Mime\Part as MimePart;

$mail = new Message();
$mail->setFrom('somebody@example.com', '山田太郎');
$mail->addTo('somebody_else@example.com', '鈴木花子');
$mail->setSubject('会議のお知らせ');

// ISO-2022-JP only uses 7-bit bytes, so the part can be sent as 7bit.
$part           = new MimePart(mb_convert_encoding('明日の会議は十時からです。', 'ISO-2022-JP', 'UTF-8'));
$part->type     = Mime::TYPE_TEXT;
$part->charset  = 'ISO-2022-JP';
$part->encoding = Mime::ENCODING_7BIT;

$body = new MimeMessage();
$body->addPart($part);
$mail->setBody($body);
$mail->setEncoding('ISO-2022-JP');
```

The body is then sent with:

```text
Content-Type: text/plain;
 charset="ISO-2022-JP"
Content-Transfer-Encoding: 7bit
```

## Plain string bodies

When the body is a plain string rather than a MIME message, no content headers
are added for you. Declare the character set with a `Content-Type` header, and
the transfer encoding with a `Content-Transfer-Encoding` header:

```php
use Contenir\Mail\Header\ContentTransferEncoding;
use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Header\MimeVersion;
use Contenir\Mail\Message;
use Contenir\Mail\Mime\TransferEncoding;

$mail = new Message();
$mail->setFrom('somebody@example.com');
$mail->addTo('somebody_else@example.com');
$mail->setSubject('Café menu');
$mail->setEncoding('UTF-8');
$mail->setBody('Today: crème brûlée.');

$mail->setHeader(new MimeVersion());
$mail->setHeader(new ContentType('text/plain', ['charset' => $mail->getEncoding()]));
$mail->setHeader(new ContentTransferEncoding(TransferEncoding::EightBit));
```

This writes:

```text
From: somebody@example.com
To: somebody_else@example.com
Subject: =?UTF-8?Q?Caf=C3=A9=20menu?=
MIME-Version: 1.0
Content-Type: text/plain;
 charset="UTF-8"
Content-Transfer-Encoding: 8bit

Today: crème brûlée.
```
