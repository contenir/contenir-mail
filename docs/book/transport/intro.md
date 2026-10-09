# Transports

Transports deliver mail. `Contenir\Mail\Transport\TransportInterface` defines one
method, `send(Message $message): void`, and five transports implement it:

Transport   | Delivers by                                   | Settings
----------- | --------------------------------------------- | --------
`Smtp`      | An SMTP server, with STARTTLS and AUTH        | [`SmtpConfig`](smtp-options.md)
`Sendmail`  | The local sendmail program, run directly or through PHP's `mail()` | `SendmailConfig`
`File`      | Writing each message to a new file            | [`FileConfig`](file-options.md)
`InMemory`  | Keeping the last message, for tests           | none
`Failover`  | The first of several transports that succeeds | the transports

Each transport keeps its settings in a read-only `*Config` object. A constructor
takes either that object or the same settings as an array, as in laminas-mail.
Unknown keys and values of the wrong type throw an exception that names the
key. Keys are snake_case; strings from environment variables such as `"587"` or
`"false"` are accepted where they are unambiguous.

## SMTP

```php
use Contenir\Mail\Message;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Transport\Smtp;
use Contenir\Mail\Transport\SmtpConfig;

$message = (new Message())
    ->addFrom('orders@example.com')
    ->addTo('jo@example.org')
    ->setSubject('Your order')
    ->setText('Thank you for your order.');

$transport = new Smtp(new SmtpConfig(
    host: 'smtp.example.com',
    port: 587,
    auth: new Login('orders', $password),
));

// or, from a configuration file
$transport = new Smtp([
    'host' => 'smtp.example.com',
    'port' => '587',
    'auth' => ['type' => 'login', 'username' => 'orders', 'password' => $password],
]);

$transport->send($message);
```

**STARTTLS is required by default.** A server that does not offer STARTTLS, or
refuses it, is refused; the session never continues in plain text. Use
`security: Security::Tls` (`'security' => 'tls'`) for TLS from the start on port
465, or `Security::None` explicitly for a local relay without TLS. Without a
port, SMTP connects to 587 for STARTTLS (the submission port), 465 for
`Security::Tls` and 25 for `Security::None`. See
[SMTP options](smtp-options.md), [SMTP authentication](smtp-authentication.md)
and [sending several messages](smtp-multiple-send.md).

The message is written to a `php://temp` stream, which keeps up to 2 MB in
memory and the rest in a temporary file, and sent from there in 64 KB
chunks, so an attachment read from a stream is never held in memory as a
whole. The session log records the message as `[DATA n bytes]` rather than its
text. `Protocol\Smtp::dataFromStream($stream)` sends a message from any
seekable stream the same way; it reads the stream twice, first to refuse a
line longer than 998 bytes before anything is sent.

## Sendmail

```php
use Contenir\Mail\Transport\Sendmail;

$transport = new Sendmail(['path' => '/usr/sbin/sendmail']); // run sendmail without a shell
$transport = new Sendmail();                                  // through PHP's mail()
$transport = new Sendmail(['parameters' => '-R hdrs']);       // SendmailConfig settings
$transport = new Sendmail('-R hdrs');                         // the laminas-mail form
$transport->send($message);
```

**With a `path`** (`new SendmailConfig(path: '/usr/sbin/sendmail')`), the
program is run directly through `proc_open()`, with no shell involved. It is run
as `path [parameters] -oi -f sender -- recipients`, with the message on standard
input. The recipients are the To, Cc and Bcc addresses, and the Bcc header is
left out of the message. A sender starting with `-` is refused, and a non-zero
exit status throws `Transport\Exception\RuntimeException` with what the program
wrote to standard error. A program still running after `timeout` seconds
(60 by default) is stopped, and the send throws. This is the recommended way to
use sendmail.

**Without a `path`**, PHP's `mail()` is used. The envelope sender is passed to
sendmail as `-f` and taken from the message's Sender, or else its first From
address. PHP runs sendmail through a shell and
escapes the parameters itself, so the sender is only passed when it consists of
letters, digits and `. _ + = -` before the `@`; any other sender throws rather
than being quoted. Give `-f` in the parameters to choose the envelope sender
yourself. The parameters may only contain letters, digits and `@ . _ + = : , / % -`.

A test can pass its own mailer, which receives what `mail()` would:

```php
$transport = new Sendmail(mailer: function (string $to, string $subject, string $body, string $headers, string $parameters): void {
    // ...
});
```

## File

```php
use Contenir\Mail\Transport\File;

$transport = new File(['path' => '/var/mail-out']);
$transport->send($message);
echo $transport->getLastFile();
```

The message is written to the file as it is made, with `Message::writeTo()`.
A file that cannot be finished is removed. See
[File transport options](file-options.md).

## InMemory

```php
use Contenir\Mail\Transport\InMemory;

$transport = new InMemory();
$transport->send($message);

$sent = $transport->getLastMessage();
```

## Failover

`Failover` sends through the first of its transports that succeeds, trying each
in turn, for example a second SMTP relay when the first is down:

```php
use Contenir\Mail\Transport\Failover;
use Contenir\Mail\Transport\Smtp;

$transport = new Failover(
    new Smtp(['host' => 'smtp1.example.com']),
    new Smtp(['host' => 'smtp2.example.com']),
);
$transport->send($message);
```

A transport that fails with one of this package's exceptions is skipped. When
every transport fails, the send throws `Transport\Exception\RuntimeException`
naming each transport and its reason, with the last failure as its previous
exception. Other errors, such as a bug in a custom transport, are not caught.

## Headers on the wire

Before a transport writes a message it checks every header for a line break that
is not folding (CRLF followed by a space or tab). The built-in headers never
produce one; the check stops a custom `HeaderInterface` implementation from
adding headers of its own, such as a hidden `Bcc`.

## Container configuration

`Contenir\Mail\ConfigProvider` registers a PSR-11 factory for
`TransportInterface`, which reads `$config['mail']['transport']`. Any PSR-11
container that reads the `dependencies` key (Mezzio, laminas-servicemanager 3
or 4) can use it, and `Contenir\Mail\Module` registers the same for
laminas-mvc. The factory needs `psr/container`, which contenir-mail suggests
rather than requires; every container installs it, and code that builds its
transports itself never loads the factory.

```php
return [
    'mail' => [
        'transport' => [
            'type' => 'smtp',          // required: smtp, sendmail, file or in-memory
            'host' => 'smtp.example.com',
            'port' => 587,
            'auth' => [
                'type'     => 'login',
                'username' => 'orders',
                'password' => getenv('SMTP_PASSWORD'),
            ],
        ],
    ],
];
```

`type` is required. Without it the factory throws rather than quietly sending
through the local sendmail. The other keys are the chosen transport's settings.
A `failover` lists its transports, each configured the same way:

```php
'transport' => [
    'type'       => 'failover',
    'transports' => [
        ['type' => 'smtp', 'host' => 'smtp1.example.com'],
        ['type' => 'smtp', 'host' => 'smtp2.example.com'],
    ],
],
```

```php
$transport = $container->get(Contenir\Mail\Transport\TransportInterface::class);
```

The keys besides `type` are the transport's Config keys. The in-memory transport
takes none.

## Migrating from laminas-mail

The [migration guide](../migrating.md) maps the laminas-mail transport settings
to these. Note that `security: 'tls'` is TLS from the start, where laminas-mail's
`ssl: 'tls'` meant STARTTLS, and that STARTTLS is now required by default.
