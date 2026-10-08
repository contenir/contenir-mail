# Signing with DKIM

DKIM ([RFC 6376](https://www.rfc-editor.org/rfc/rfc6376)) lets a receiving
server check that a message really comes from your domain and wasn't changed on
the way. The sender signs some headers and the body with a private key, and
publishes the public key in DNS. `Contenir\Mail\Dkim\Signer` adds the
`DKIM-Signature` header, so you don't need the MTA to sign for you.

```php
use Contenir\Mail\Dkim\Signer;

$signer = new Signer([
    'domain'           => 'example.com',
    'selector'         => 'mail2026',
    'private_key_path' => '/etc/dkim/mail2026.pem',
]);

$transport->send($signer->sign($message));
```

`sign()` returns a new message with the `DKIM-Signature` header first. The
message you gave it isn't changed.

## Publishing the key

Receivers look the key up at `selector._domainkey.domain`, here
`mail2026._domainkey.example.com`. `PrivateKey::dnsRecord()` gives the TXT
record to publish:

```php
use Contenir\Mail\Dkim\PrivateKey;

echo PrivateKey::fromFile('/etc/dkim/mail2026.pem')->dnsRecord();
// v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA…
```

Use a new selector when you change keys, and keep the old record published
until mail signed with the old key has been delivered.

## Choosing a key

**RSA** (`rsa-sha256`) is understood by every receiver. Use a key of **2048
bits or more**. Keys under 1024 bits are refused, as RFC 8301 requires, and
1024-bit keys are accepted only because some DNS hosts can't publish a longer
record. They are weak and shouldn't be used for new keys.

```bash
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out mail2026.pem
```

**Ed25519** (`ed25519-sha256`, [RFC 8463](https://www.rfc-editor.org/rfc/rfc8463))
gives short keys and signatures, but not every receiver verifies it yet. Sign
with both and receivers use the one they understand:

```bash
openssl genpkey -algorithm ed25519 -out mail2026-ed.pem
```

```php
$message = $ed25519Signer->sign($rsaSigner->sign($message));
```

The two signatures need different selectors. `rsa-sha1` isn't offered: RFC 8301
forbids signing with it.

The algorithm follows the key. RSA keys need `ext-openssl`, and Ed25519 keys
need `ext-sodium`. Reading an Ed25519 key from PEM needs both. A missing
extension is reported when the key is read.

## Giving the key

`PrivateKey` reads a key in any of these forms:

Method | Reads
--- | ---
`PrivateKey::fromFile($path, $passphrase)` | A PEM file, RSA or Ed25519, encrypted or not, or a file holding an Ed25519 key in base64
`PrivateKey::fromPem($pem, $passphrase)` | The same PEM, as a string
`PrivateKey::fromOpenSsl($key)` | A key already loaded with `openssl_pkey_get_private()`
`PrivateKey::fromEd25519($key)` | An Ed25519 key: the 32-byte seed or the 64-byte libsodium secret key, raw or in base64

In settings, give `private_key` (a PEM key, or an Ed25519 key in base64) or
`private_key_path`, and `private_key_passphrase` for an encrypted PEM key.

The key is kept inside the `PrivateKey` object. `var_dump()` and `print_r()`
show it as `[hidden]`, and the object refuses to be serialized, so it can't end
up in a queue or a cache. An encrypted key without its passphrase is refused;
OpenSSL is never left to ask for one on the terminal. Keep key files readable
only by the user that sends mail.

## Settings

Construct `DkimConfig` with named arguments, or give `Signer` the same settings
as an array, which `DkimConfig::fromIterable()` reads:

```php
use Contenir\Mail\Dkim\DkimConfig;
use Contenir\Mail\Dkim\PrivateKey;
use Contenir\Mail\Dkim\Signer;

$signer = new Signer(new DkimConfig(
    domain: 'example.com',
    selector: 'mail2026',
    privateKey: PrivateKey::fromFile('/etc/dkim/mail2026.pem'),
    expiresAfter: 7 * 86400,
), $clock);
```

Setting | Tag | Default | Meaning
--- | --- | --- | ---
`domain` | `d=` | required | The signing domain. Its record holds the public key
`selector` | `s=` | required | The selector. The record is at `selector._domainkey.domain`
`privateKey`, or `private_key`, `private_key_path` and `private_key_passphrase` | `a=` | required | The key, which sets the algorithm
`expectedAlgorithm` | `a=` | the key's | Only checks the key is the one expected
`headers` | `h=` | see below | The headers to sign when the message has them
`headerCanonicalization` | `c=` | `relaxed` | `relaxed` or `simple`, for the headers
`bodyCanonicalization` | `c=` | `relaxed` | `relaxed` or `simple`, for the body
`identity` | `i=` | none | The user or agent signed for, at the domain or a subdomain of it, such as `@news.example.com`
`includeTimestamp` | `t=` | on | Write the signing time, from the signer's clock
`expiresAfter` | `x=` | none | Seconds after signing that the signature expires
`signBodyLength` | `l=` | off | Write the length of the signed body. Leave it off, see below

Domains and selectors must be ASCII domain names. White space, `;` and control
characters are refused, so a setting can't add a tag of its own.

`t=` and `x=` come from the PSR-20 clock given to `Signer`, which is the system
clock unless you give another.

### Signed headers

By default the signer signs From, To, Cc, Subject, Date, Message-ID, Reply-To,
In-Reply-To, References, MIME-Version, Content-Type and
Content-Transfer-Encoding. It signs those the message has, and lists each in
`h=` once for every time it appears, so it signs every instance.

From must be in the list (RFC 6376, section 5.4), and a message without a From
header can't be signed. Bcc can't be listed, because transports remove it
before sending and the signature would then fail. DKIM-Signature can't be
listed either.

### Canonicalisation

`relaxed` (the default, for both headers and body) allows the changes relays
often make: refolded headers, a different case in header names, and changed
spaces. `simple` allows no change at all, so a signature made with it fails
more often in transit.

### Why l= is off by default

With `l=`, the signature covers only the first part of the body, so anyone
who relays the message can add text after it, such as a new MIME part with
their own links. The signature still verifies, and mail clients show the
added text as if you had sent it. Leave `signBodyLength` off unless a mailing list
you send through adds a footer and you accept that risk.

## Sending the signed message

The signature covers the headers and body exactly as they're sent. So
`sign()` returns a message that holds them as they were signed:

- the headers are the ones `getHeaders()` gave, with the generated Message-ID
  and the MIME headers included;
- the body is the text the transports would have written, with every line
  ending in CRLF. The MIME tree is already written out, so it isn't encoded
  again when the message is sent.

Send the signed message as it is. Setting a header, the subject or the body
afterwards breaks the signature.

The transports send the signed message unchanged:

- **SMTP** doubles a leading `.` on the wire (dot-stuffing), and the receiving
  server removes it. The signature covers the body without the extra dots.
  Bare CR and LF were already written as CRLF when signing, so the body hash
  matches what the server receives. Bcc is removed, and isn't signed.
- **Sendmail** with a `path` sends the message with LF line endings, and the
  MTA turns them back into CRLF. Through `mail()`, PHP writes To and Subject
  itself. Use `relaxed` header canonicalisation (the default) with `mail()`.
- **File** writes the message byte for byte.

## What isn't included

The signer only signs. It doesn't verify incoming signatures; leave that to
the receiving MTA. It doesn't oversign either: listing a header the message
doesn't have, to stop it being added later.
