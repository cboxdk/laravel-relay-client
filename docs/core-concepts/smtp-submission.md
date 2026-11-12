---
title: SMTP as an alternative
description: Relay also takes mail over SMTP, for apps that cannot call its API. When to use it instead of this package, and what you give up.
weight: 36
---

# SMTP as an alternative

This package talks to Relay's HTTP API, and that is the way to send from a Laravel app:
`MAIL_MAILER=relay` and you are done.

Relay can also take mail over SMTP, for software that cannot call an API: a CMS, a
printer, a legacy system with only an "SMTP server" field. The user name is a project (or
`relay`), the password is a Relay API key with `mail:send`, on port 587 with STARTTLS. A
mail handed in that way is the same mail afterwards: it shows up in Relay's console and
in the webhooks this package receives, under the same rules for sending domains,
suppressions and the filter.

Relay's own guide has the host name, what is supported and whether the submission server
is running yet: see the SMTP page of Relay's API documentation (`docs/api/smtp.md` in
Relay). Check it before pointing anything at it.

## Why this package does not use it

Laravel could send through Relay with its plain `smtp` mailer instead of the `relay`
transport. You would give up:

- **The mail's id.** The `relay` transport leaves `X-Relay-Mail-Id` on the sent message
  and feeds the [local delivery status](delivery-status.md). Over SMTP the app only hears
  "250".
- **An idempotency key**, so a retried job cannot send twice.
- **The per-mail controls**: project, click and open tracking, a send time.
- **Typed errors.** A refusal is an SMTP reply text, not a `RelayInvalid` with field errors.

Tags and metadata do not travel over SMTP either. Use it for what cannot do better.
