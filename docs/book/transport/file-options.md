# File Transport Options

`Contenir\Mail\Transport\File` writes each message to a new file, for development
and testing. Its settings are a `Contenir\Mail\Transport\FileConfig`.

```php
use Contenir\Mail\Transport\File;
use Contenir\Mail\Transport\FileConfig;

$transport = new File(new FileConfig(
    path: '/var/mail-out',
    callback: static fn(File $transport): string => sprintf('Message_%s.eml', bin2hex(random_bytes(8))),
));

$transport = new File(['path' => '/var/mail-out']);

$transport->send($message);
echo $transport->getLastFile();
```

Key        | Argument   | Default                  | Meaning
---------- | ---------- | ------------------------ | -------
`path`     | `path`     | the system temp directory | A writable local directory.
`callback` | `callback` | a random name            | A Closure or invokable object, called with the transport; returns the name of the next file. Function names are not accepted.

The default name is `ContenirMail_<time>_<16 random hex digits>.eml`.

## Safety

- `path` must be a local directory: stream wrapper URLs such as `phar://` are
  refused, and so is a symlink, which could later be pointed elsewhere.
- The callback's result must be a plain file name: no `/`, `\`, `:` or control
  characters, and not `.` or `..`, so no name can leave the directory.
- Each file is created new, with permissions 0600. If a file or symlink with the
  name already exists, the send fails rather than overwrite or follow it, which
  protects a shared directory such as `/tmp` from names planted in advance.
- Headers are checked for unfolded line breaks, as by every transport.

## Methods

```php
__construct(FileConfig|iterable|null $config = null, ClockInterface $clock = new SystemClock())
getConfig(): FileConfig
send(Message $message): void
getLastFile(): ?string
```
