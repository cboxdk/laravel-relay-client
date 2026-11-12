# Security Policy

## Supported versions

The latest minor of the current major receives security fixes.

## Reporting a vulnerability

Please do not open a public issue. Email [sn@cbox.dk](mailto:sn@cbox.dk) with a
description and, if possible, a proof of concept. Reports are handled on a best-effort
basis; there is no fixed response time.

Areas of particular interest for this package:

- The API key (`RELAY_KEY`): it must never appear in a log line or an exception message.
  The package puts it in the `Authorization` header and nowhere else.
- The `Relay-Scope` header: an organization key acting for one of its scopes. A value
  taken from user input lets that user pick the scope. See
  [docs/security/threat-model.md](docs/security/threat-model.md).
