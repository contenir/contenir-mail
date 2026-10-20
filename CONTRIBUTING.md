# Contributing

Thank you for helping. Bug reports, failing tests, interoperability reports
against real mail servers, and reviews of the security and standards
documentation are all as valuable as code.

## Reporting

- **Vulnerabilities:** report privately, as described in [SECURITY.md](SECURITY.md).
  Do not open a public issue.
- **Bugs:** open an issue with the version, the PHP version, what you did, what
  you expected and what happened. A failing test or a raw message that
  reproduces it is the most useful thing you can attach. Remove anything
  private from the message first.
- **Interoperability:** if a real server or client rejects or misreads what
  contenir-mail sends or expects, say which server and version, and include the
  protocol exchange if you can, with credentials removed.

## Scope

The package composes, parses, sends, reads and stores mail. Features that fit
that scope are welcome; open an issue before starting anything large, so we can
agree the design first. Integrations with a single mail provider's HTTP API,
templating and queueing belong in separate packages that build on this one.

## Making a change

1. Branch from the newest release branch (currently `0.3.x`) and open the pull
   request against it.
2. Add or update tests. Unit tests go in `tests/Unit` and must not touch the
   network or the file system. Tests that need a socket, a process or a real
   server go in `tests/Integration`; see its [README](tests/Integration/README.md).
3. Follow the [naming conventions](docs/book/conventions.md) for any new public API.
4. Add an entry under the unreleased section of [CHANGELOG.md](CHANGELOG.md).
   A change that breaks the public API also needs a note in
   [the migration guide](docs/book/migrating.md).
5. If the change affects a protection in [security.md](docs/book/security.md) or
   conformance in [standards.md](docs/book/standards.md), update that document too.

## Checks

Pull requests must pass the same checks CI runs:

```bash
composer check            # mago format and lint, mago analyze, unit tests
composer test-integration # integration tests; those needing servers are skipped
composer mutation-test    # Infection; needs pcov or Xdebug
```

`composer cs-fix` fixes most formatting and lint findings. CI fails if any mutant
of covered code survives, so a new test must fail when the code it covers is
broken, not only run it.

To run the integration suite against Dovecot, Postfix and Mailpit, follow
[tests/Integration/README.md](tests/Integration/README.md). It needs Docker.
CI runs it on every pull request.

## History and licensing

The repository keeps the full history of laminas-mail and laminas-mime. Please
do not rewrite or squash inherited history. Contributions are accepted under the
[BSD-3-Clause licence](LICENSE.md).
