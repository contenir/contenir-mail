# Sending Several Messages per SMTP Connection

An SMTP transport connects on its first `send()` and reuses the session for the
messages after it, sending `RSET` before each one.

```php
use Contenir\Mail\Message;
use Contenir\Mail\Transport\Smtp;

$transport = new Smtp(['host' => 'smtp.example.com']);

$message = (new Message())
    ->addFrom('sender@example.com', 'John Doe')
    ->addReplyTo('replyto@example.com', 'Jane Doe')
    ->setSubject('Demo of several messages per SMTP connection')
    ->setText('... Your message here ...');

foreach ($recipients as $address) {
    $message->setTo($address);
    $transport->send($message);
}
```

Each entry in `$recipients` can be anything `setTo()` accepts, such as an address
string, `'Name <email@example.com>'` or a `Contenir\Mail\Address`.

The connection is closed when the transport is destroyed, or by
`$transport->disconnect()`. Call `setAutoDisconnect(false)` to leave it open when
the transport is destroyed, for a connection shared with other code.

## Controlling the session yourself

Give the transport a `Contenir\Mail\Protocol\Smtp` to manage the session
directly. The transport opens the session if it has not been opened.

```php
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Smtp as SmtpProtocol;
use Contenir\Mail\Transport\Smtp as SmtpTransport;

$protocol = new SmtpProtocol(new ConnectionConfig('smtp.example.com'));
$protocol->connect();
$protocol->helo('sender.example.com');

$transport = new SmtpTransport();
$transport->setConnection($protocol);

foreach ($messages as $message) {
    $transport->send($message);
}

$protocol->quit();
$protocol->disconnect();
```

`setConnection()` applies the transport's `use_complete_quit` and
`connection_time_limit` settings to the protocol.
