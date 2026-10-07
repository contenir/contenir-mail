# Security policy

## Reporting a vulnerability

Please report vulnerabilities privately through GitHub's
[private vulnerability reporting](https://github.com/contenir/contenir-mail/security/advisories/new)
for this repository. Do not open a public issue.

Include the version or commit, what an attacker controls, the effect, and if you
can, a failing test or a minimal script. You will get an acknowledgement within
five working days.

## Supported versions

Until 1.0 is released, fixes are made on the latest development branch only.

## How security is handled

The protections this package relies on, the threat model behind them and the
known open findings are documented in
[docs/book/security.md](docs/book/security.md). Every protection has a regression
test, and CI fails if mutation testing finds a test that no longer guards its
code.
