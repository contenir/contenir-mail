# Character Sets

Headers and body handle character sets in different ways.

- **Headers encode themselves.** Plain ASCII values are written as they are.
  Any other text, such as an accented display name or a subject in Japanese, is
  encoded as an RFC 2047 UTF-8 "encoded word" automatically. You do not need to
  configure anything for this.
- **Each text part declares its own character set.** `setText()` and
  `setHtml()` take the character set as their second argument, `UTF-8` by
  default, and write it into the part's `Content-Type`.

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

$mail = new Message();

// Header text is given as UTF-8; it is encoded when the message is written.
$mail->setFrom('somebody@example.com', '山田太郎');
$mail->addTo('somebody_else@example.com', '鈴木花子');
$mail->setSubject('会議のお知らせ');
$mail->setText('明日の会議は十時からです。');

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

The body can use any character set your recipients' mail clients support.
Convert the text, then name the character set. Headers are still written as
UTF-8 encoded words, which mail clients decode independently of the body.

```php
$mail->setText(mb_convert_encoding('Crème brûlée', 'ISO-8859-1', 'UTF-8'), 'ISO-8859-1');
```

To choose the transfer encoding as well, create the part yourself. ISO-2022-JP
only uses 7-bit bytes, so it can be sent as `7bit`:

```php
use Contenir\Mail\Mime\Mime;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Mime\TransferEncoding;

$mail->setBody(new Part(
    mb_convert_encoding('明日の会議は十時からです。', 'ISO-2022-JP', 'UTF-8'),
    type: Mime::TYPE_TEXT,
    encoding: TransferEncoding::SevenBit,
    charset: 'ISO-2022-JP',
));
```

The body is then sent with:

```text
Content-Type: text/plain;
 charset="ISO-2022-JP"
Content-Transfer-Encoding: 7bit
```

## Plain string bodies

`setBody()` also accepts a string, which is sent exactly as it is. No content
headers are added for a string body, so declare its character set and transfer
encoding yourself when it is not ASCII:

```php
use Contenir\Mail\Header\ContentTransferEncoding;
use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Header\MimeVersion;
use Contenir\Mail\Mime\TransferEncoding;

$mail->setBody('Today: crème brûlée.');
$mail->setHeader(new MimeVersion());
$mail->setHeader(new ContentType('text/plain', ['charset' => 'UTF-8']));
$mail->setHeader(new ContentTransferEncoding(TransferEncoding::EightBit));
```

`setText()` is usually simpler: it declares the character set and encodes the
text as quoted-printable, which every mail server accepts.
