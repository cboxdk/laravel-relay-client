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
- The `relay.webhook` middleware and `Webhooks\WebhookSignature`: anything that lets a
  request through without a valid signature, or with a timestamp outside the tolerance.
- The `relay` mail transport: a header or recipient reaching Relay that the mail did
  not carry, or a control header (`X-Relay-Project`, `X-Relay-Idempotency-Key`) passed on.
- The `Relay-Project` header: an organization key acting for one of its projects. A value
  taken from user input lets that user pick the project. See
  [docs/security/threat-model.md](docs/security/threat-model.md).
