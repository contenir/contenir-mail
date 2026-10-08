# Provider smoke test

`tests/Smoke/smoke.php` checks contenir-mail against a real provider, using your own account. It runs these checks in order:

1. **Get an access token**, if one is needed. For Microsoft, it signs you in with a device code.
2. **Send** a message to yourself over SMTP with STARTTLS. The message has a UTF-8 body, a line starting with a dot, and an attachment.
3. **Read it back over IMAP** with TLS, using the access token or the password.
4. **Read it back over POP3** with TLS, using the access token or the password.
5. **Try a token the provider never issued.** The server must refuse it with a readable error. A raw base64 error, or a session left waiting, is a failure.

Each check prints `PASS`, `FAIL` or `SKIP`. A check that can't run with the credentials you gave is skipped. The script exits with 0 when nothing failed.

Credentials come from environment variables and are never printed or stored. The test messages stay in your mailbox; delete them when you're done. Their subjects start with `contenir-mail smoke test`.

CI runs the same script against Postfix and Dovecot, once with a password and once with a token (see `.github/workflows/integration.yml`). So when it fails against a provider, the difference is the provider.

## Gmail

Gmail accepts an app password, or an OAuth access token.

1. **Turn on 2-Step Verification** for the account. App passwords need it.
2. **Create an app password** at <https://myaccount.google.com/apppasswords>.
3. **Turn on POP:** Gmail settings, then "Forwarding and POP/IMAP", then "Enable POP". Without it, the POP3 check fails with a login error.

```sh
SMOKE_PROVIDER=gmail SMOKE_USER=you@gmail.com SMOKE_PASSWORD='app password' php tests/Smoke/smoke.php
```

To also test OAuth, get an access token from the [OAuth 2.0 Playground](https://developers.google.com/oauthplayground):

1. Under "Select & authorize APIs", enter the scope `https://mail.google.com/`.
2. Choose "Authorize APIs" and sign in.
3. Choose "Exchange authorization code for tokens".
4. Copy the access token. It lasts about an hour.

```sh
SMOKE_PROVIDER=gmail SMOKE_USER=you@gmail.com SMOKE_PASSWORD='app password' SMOKE_TOKEN='ya29.…' php tests/Smoke/smoke.php
```

With a token, SMTP, IMAP and POP3 all sign in with XOAUTH2, and the app password isn't needed.

## Outlook.com (personal Microsoft accounts)

Microsoft accepts only OAuth for IMAP, POP3 and SMTP. The script gets the token itself with a device code. It needs a free app registration:

1. **Register an app.** In the [Azure portal](https://portal.azure.com), go to "App registrations", then "New registration". Choose "Personal Microsoft accounts only". No redirect URI is needed.
2. **Allow public client flows.** Under "Authentication", set "Allow public client flows" to Yes, and save.
3. **Copy the client ID.** It's the "Application (client) ID" on the overview page.

```sh
SMOKE_PROVIDER=outlook SMOKE_USER=you@outlook.com SMOKE_MS_CLIENT_ID='client id' php tests/Smoke/smoke.php
```

The script prints a code and a web address. Open the address, enter the code and sign in. The script continues once you've approved access to send mail and read it over IMAP and POP3.

## Microsoft 365 (work or school accounts)

This works as for Outlook.com, with these differences:

- **Account type:** register the app for "Accounts in any organizational directory", in a tenant you can use.
- **Admin consent:** your tenant may require an administrator to consent to the app.
- **SMTP AUTH and POP:** these must be turned on for the mailbox. In the Microsoft 365 admin center, open the user, go to "Mail", then "Manage email apps", and turn on "Authenticated SMTP" and "POP". SMTP AUTH is off by default in many tenants. When it's off, the send check fails with `5.7.139`.

```sh
SMOKE_PROVIDER=office365 SMOKE_USER=you@company.com SMOKE_MS_CLIENT_ID='client id' php tests/Smoke/smoke.php
```

## Any other provider

```sh
SMOKE_PROVIDER=custom SMOKE_USER=you@example.com SMOKE_PASSWORD='…' \
SMOKE_SMTP_HOST=smtp.example.com SMOKE_IMAP_HOST=imap.example.com SMOKE_POP3_HOST=pop.example.com \
php tests/Smoke/smoke.php
```

The defaults are STARTTLS on port 587 for SMTP, and TLS on ports 993 and 995. Change the ports with `SMOKE_SMTP_PORT`, `SMOKE_IMAP_PORT` and `SMOKE_POP3_PORT`.

## All settings

| Variable | Meaning |
| --- | --- |
| `SMOKE_PROVIDER` | `gmail`, `outlook`, `office365` or `custom` |
| `SMOKE_USER` | The account's address, which is also the sign-in name |
| `SMOKE_PASSWORD` | A password or app password, for providers that still accept one |
| `SMOKE_TOKEN` | An OAuth access token, used for SMTP and POP3 |
| `SMOKE_MS_CLIENT_ID` | A Microsoft app registration's client ID, used to sign in with a device code |
| `SMOKE_TO` | Where to send the message; defaults to `SMOKE_USER` |
