# Integration tests

These tests run contenir-mail against real mail servers in Docker:

- **[Dovecot](https://www.dovecot.org/)** for IMAP and POP3, over plain connections, STARTTLS and implicit TLS.
- **[Postfix](https://www.postfix.org/)** for SMTP submission on port 587, with STARTTLS required. It checks logins against Dovecot, including XOAUTH2, and delivers to Dovecot over LMTP. The tests then read the message back over IMAP.
- **[Mailpit](https://mailpit.axllent.org/)** for SMTP, with STARTTLS and AUTH required. Its HTTP API reads back what was received.
- **[GreenMail](https://greenmail-mail-test.github.io/greenmail/)** for SMTP, IMAP and POP3 over TLS from the start, as a second opinion from another implementation.

The first Dovecot accepts XOAUTH2 and OAUTHBEARER, and checks the token as the user's password. A second, strict Dovecot is set up as in production:

- It refuses passwords before TLS, so IMAP advertises `LOGINDISABLED` and POP3 refuses `USER`.
- It checks access tokens with its oauth2 passdb, which asks a stub token introspection endpoint (RFC 7662, `servers/oauth/introspect.py`) whether a token is active. Only `valid-token-for-test` is.

Postfix checks logins against the first Dovecot on port 587, and against the strict one on port 2587. It accepts messages up to 1 MiB, so a test can exceed its `SIZE`.

Each run creates a throwaway certificate authority. Its certificates name `localhost` only, so connecting to `127.0.0.1` tests that a mismatched name is refused. Two more Dovecots test what verification refuses: one has a certificate from the same CA that expired in 2024, and the other a certificate that signs itself.

| Server | Ports |
|---|---|
| Dovecot | 143, 993 (IMAP); 110, 995 (POP3) |
| Strict Dovecot | 1143, 1993 (IMAP); 1110, 1995 (POP3) |
| Dovecot, expired certificate | 2143, 2993 (IMAP) |
| Dovecot, self-signed certificate | 4143, 4993 (IMAP) |
| Postfix | 587, 2587 |
| Mailpit | 1025 (SMTP), 8025 (HTTP) |
| GreenMail | 3465 (SMTP), 3993 (IMAP), 3995 (POP3), 3143 (to check it is up) |

`tests/Integration/TestAsset/Servers.php` lists the same ports for the tests.

Without the environment variables below, the tests are skipped, so `vendor/bin/phpunit` alone never needs a server.

## Running locally

```sh
cafile=$(tests/Integration/servers/start.sh)

TESTS_CONTENIR_MAIL_SERVERS_ENABLED=1 \
TESTS_CONTENIR_MAIL_SERVER_TESTDIR="$PWD/tests/Integration/servers/var/mail/test" \
TESTS_CONTENIR_MAIL_SERVER_FORMAT=mbox \
TESTS_CONTENIR_MAIL_IMAP_ENABLED=1 TESTS_CONTENIR_MAIL_IMAP_HOST=localhost \
TESTS_CONTENIR_MAIL_IMAP_USER=test TESTS_CONTENIR_MAIL_IMAP_PASSWORD=secret \
TESTS_CONTENIR_MAIL_IMAP_SSL=1 TESTS_CONTENIR_MAIL_IMAP_TLS=1 \
TESTS_CONTENIR_MAIL_IMAP_WRONG_PORT=80 TESTS_CONTENIR_MAIL_IMAP_INVALID_PORT=3141 \
TESTS_CONTENIR_MAIL_POP3_ENABLED=1 TESTS_CONTENIR_MAIL_POP3_HOST=localhost \
TESTS_CONTENIR_MAIL_POP3_USER=test TESTS_CONTENIR_MAIL_POP3_PASSWORD=secret \
TESTS_CONTENIR_MAIL_POP3_SSL=1 TESTS_CONTENIR_MAIL_POP3_TLS=1 \
TESTS_CONTENIR_MAIL_POP3_WRONG_PORT=80 TESTS_CONTENIR_MAIL_POP3_INVALID_PORT=3141 \
TESTS_CONTENIR_MAIL_POSTFIX_ENABLED=1 TESTS_CONTENIR_MAIL_POSTFIX_HOST=localhost \
TESTS_CONTENIR_MAIL_POSTFIX_PORT=587 \
TESTS_CONTENIR_MAIL_SMTP_ENABLED=1 TESTS_CONTENIR_MAIL_SMTP_HOST=localhost \
TESTS_CONTENIR_MAIL_SMTP_PORT=1025 TESTS_CONTENIR_MAIL_SMTP_API=http://localhost:8025 \
TESTS_CONTENIR_MAIL_SMTP_USER=test TESTS_CONTENIR_MAIL_SMTP_PASSWORD=secret \
php -d openssl.cafile="$cafile" vendor/bin/phpunit --testsuite integration

docker compose -f tests/Integration/servers/compose.yml down
```

`TESTS_CONTENIR_MAIL_SERVERS_ENABLED` runs the tests that use the ports in the table, with the user `test` and the password `secret`. The other variables come from the laminas-mail tests, which can be pointed at any server.

The IMAP and POP3 tests copy the mbox fixtures from `tests/Unit/_files/test.mbox` into the server's mail folder before each test. They clear that folder first, which is why `TESTS_CONTENIR_MAIL_SERVER_TESTDIR` must point at `servers/var/mail/test`, never at a real mailbox.

CI runs the same steps in `.github/workflows/integration.yml`.
