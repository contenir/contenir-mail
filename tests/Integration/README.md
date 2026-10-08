# Integration tests

These tests run contenir-mail against real mail servers in Docker:

- **[Dovecot](https://www.dovecot.org/)** for IMAP and POP3, over plain connections, STARTTLS and implicit TLS.
- **[Mailpit](https://mailpit.axllent.org/)** for SMTP, with STARTTLS and AUTH required. Its HTTP API reads back what was received.

Each run creates a throwaway certificate authority, used for both servers' certificates. The certificates name `localhost` only, so connecting to `127.0.0.1` tests that a mismatched name is refused.

Without the environment variables below, the tests are skipped, so `vendor/bin/phpunit` alone never needs a server.

## Running locally

The servers use ports 110, 143, 993, 995, 1025 and 8025.

```sh
cafile=$(tests/Integration/servers/start.sh)

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
TESTS_CONTENIR_MAIL_SMTP_ENABLED=1 TESTS_CONTENIR_MAIL_SMTP_HOST=localhost \
TESTS_CONTENIR_MAIL_SMTP_PORT=1025 TESTS_CONTENIR_MAIL_SMTP_API=http://localhost:8025 \
TESTS_CONTENIR_MAIL_SMTP_USER=test TESTS_CONTENIR_MAIL_SMTP_PASSWORD=secret \
php -d openssl.cafile="$cafile" vendor/bin/phpunit --testsuite integration

docker compose -f tests/Integration/servers/compose.yml down
```

The IMAP and POP3 tests copy the mbox fixtures from `tests/Unit/_files/test.mbox` into the server's mail folder before each test. They clear that folder first, which is why `TESTS_CONTENIR_MAIL_SERVER_TESTDIR` must point at `servers/var/mail/test`, never at a real mailbox.

CI runs the same steps in `.github/workflows/integration.yml`.
