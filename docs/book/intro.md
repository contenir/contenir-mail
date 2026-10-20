# Introduction

contenir-mail provides generalized functionality to compose and send both text and
MIME-compliant multipart email messages. Mail can be sent with contenir-mail via any
of the Sendmail, SMTP, or file-based transports it defines.  You can also
implement your own transport by implementing the
`Contenir\Mail\Transport\TransportInterface`.

It also reads mail: it parses messages, fetches them over IMAP and POP3, and
reads and writes Mbox and Maildir mailboxes.

## Alongside other mail libraries

PHP has several well-established mail libraries, and each suits a different way
of working. Symfony Mailer fits naturally into a Symfony application and its
tooling; PHPMailer is a long-lived, self-contained library for sending.
contenir-mail continues the Zend Framework and Laminas approach, so it will feel
familiar if you have worked that way: typed configuration, PSR-11 factories for
Mezzio, laminas-mvc or any other container, and the `Message`, `Headers`,
transport and storage vocabulary of laminas-mail.

| | contenir-mail | Symfony Mailer | PHPMailer |
| --- | --- | --- | --- |
| Compose text, HTML and attachments | Yes | Yes | Yes |
| SMTP and sendmail | Yes | Yes | Yes |
| HTTP API transports for mail providers | No | Yes | No |
| DKIM signing | Yes | Yes | Yes |
| S/MIME | No | Yes | Signing |
| Parse a received message | Yes | No | No |
| Read over IMAP and POP3 | Yes | No | No |
| Mbox and Maildir | Read and write | No | No |

Sending and reading live in the same package, so the same `Message` and MIME
classes are used whether you compose a message or parse one you received. All
of it is pure PHP and does not depend on `ext-imap`, which was removed from PHP
core in 8.4. If you are coming from laminas-mail or Zend Mail, see
[migrating](migrating.md).

## What is maintained

Every part of the package listed above is maintained and tested: messages and
MIME, the SMTP, sendmail, file and in-memory transports, the IMAP, POP3 and SMTP
protocol clients, and Mbox and Maildir storage. The protocol clients are tested
against scripted conversations in the unit suite and against real Dovecot,
Postfix and Mailpit servers in the integration suite. [Standards](standards.md)
lists how closely each RFC is followed, including the known gaps.

## Basic email

A basic email consists of one or more recipients, a subject, a body and a
sender. To send such a mail using `Contenir\Mail\Transport\Sendmail`, do the
following:

```php
use Contenir\Mail;

$mail = new Mail\Message();
$mail->setBody('This is the text of the email.');
$mail->setFrom('Freeaqingme@example.org', "Sender's name");
$mail->addTo('Matthew@example.com', 'Name of recipient');
$mail->setSubject('TestSubject');

$transport = new Mail\Transport\Sendmail();
$transport->send($mail);
```

> ### Minimum definitions
>
> In order to send an email using contenir-mail you have to specify a From
> address, at least one recipient and a message body. Please note that each
> transport may require additional parameters to be set.

For most mail attributes there are "get" methods to read the information stored
in the message object. for further details, please refer to the API
documentation.

## Configuring the default sendmail transport

The default transport is `Contenir\Mail\Transport\Sendmail`. It is a wrapper to the
PHP [mail()](http://php.net/mail) function. If you wish to pass additional
parameters to the [mail()](http://php.net/mail) function, create a new transport
instance and pass your parameters to the constructor.

### Passing additional mail() parameters

This example shows how to change the Return-Path of the
[mail()](http://php.net/mail) function.

```php
use Contenir\Mail;

$mail = new Mail\Message();
$mail->setBody('This is the text of the email.');
$mail->setFrom('Freeaqingme@example.org', 'Dolf');
$mail->addTo('matthew@example.com', 'Matthew');
$mail->setSubject('TestSubject');

$transport = new Mail\Transport\Sendmail('-freturn_to_me@example.com');
$transport->send($mail);
```

> ### Chose your transport wisely
>
> Although the sendmail transport is the transport that requires least
> configuration, it may not be suitable for your production environment. This is
> because emails sent using the sendmail transport will be more often delivered
> to SPAM-boxes. This can partly be remedied by using the
> [SMTP Transport](transport/intro.md#smtp-transport-usage) combined with an SMTP
> server that has an overall good reputation. Additionally, techniques such as
> SPF and DKIM may be employed to ensure even more email messages are delivered
> successfully.

> ### Warning: Sendmail Transport and Windows
>
> As the PHP manual states, the `mail()` function has different behaviour on
> Windows than it does on \*nix based systems. Using the sendmail transport on
> Windows will not work in combination with `addBcc()`.  The `mail()` function
> will send to the BCC recipient such that all the other recipients can see that
> address as the recipient!
>
> Therefore if you want to use BCC on a windows server, use the SMTP transport
> for sending!
