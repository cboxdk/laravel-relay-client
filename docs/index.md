---
title: Introduction
description: The Laravel client for Cbox Relay. Publish events, send mail and push, judge content, read inbound mail and verify Relay's webhooks, with an in-memory fake.
weight: 1
---

# Cbox Relay client

Cbox Relay is the service that delivers webhooks, mail and push for Cbox products. This
package is how a Laravel app talks to its API. It is an ordinary customer of Relay: an
API key, the public endpoint, nothing else.

## What it does

- **[Publishing events](core-concepts/publishing-events.md)**: `Relay::publish()` hands
  an event to a queued job that calls Relay after commit, with an idempotency key so a
  retry publishes once. `publishNow()` and `publishBatch()` call inline. A `subject`
  on an event lets `eraseSubject()` forget one person later.
- **[Webhook endpoints](core-concepts/webhook-endpoints.md)**: declare event types,
  register, pause, rotate, ping and replay endpoints, read events and their deliveries.
- **[Outbound mail](core-concepts/outbound-mail.md)**: sending domains, the suppression
  list, `Relay::sendMail()`, and a `relay` mail transport so `MAIL_MAILER=relay` works.
- **[Web Push](core-concepts/web-push.md)**: the project's key, browser subscriptions,
  `Relay::push()`, and a Laravel notification channel.
- **[Inbound mail](core-concepts/inbound-mail.md)**: claim addresses, read received
  mail with content and attachment links, erase it.
- **[The judge](core-concepts/judge.md)**: score a mail or a template without sending it.
- **[Webhooks from Relay](core-concepts/incoming-webhooks.md)**: the `relay.webhook`
  middleware verifies a delivery, and Relay's own events arrive as typed objects.
- **[Inbound webhooks](core-concepts/inbound-webhooks.md)**: sources that take a
  provider's webhooks for a project and forward them to your endpoints.
- **[Authentication and projects](core-concepts/authentication.md)**: `whoami()`,
  `forProject()`, and a clear exception when a call needs a project and has none.
- **[Projects](core-concepts/projects.md)** and **[the delivery log](core-concepts/deliveries.md)**.
- **[Typed errors](core-concepts/errors.md)**: every problem answer is its own exception.
- **[A fake](getting-started/testing.md)**: `Relay::fake()` keeps all of it in memory,
  with assertions.

## What the client is not

The client covers Relay's API. It does not decide what the service behind the API does.

- **Whether mail leaves Relay or arrives at it is Relay's.** Its API guides say the
  outbound mail servers and the receiving servers are not running yet: a mail you send
  is stored, filtered and queued and goes no further, and no mail arrives. See Relay's
  own status; nothing in this package changes when that does.
- **The judge's scores are Relay's.** Fixed rules, hand-chosen weights, not measured
  probabilities. The client carries the numbers.
- **Delivering webhooks is Relay's.** Retries, ordering, signing and disabling a dead
  endpoint happen in the service.

What this package itself has and has not verified is in
[BUILD-STATUS.md](https://github.com/cboxdk/laravel-relay-client/blob/main/BUILD-STATUS.md).

## The mental model

There is one contract, `Cbox\Relay\Client\Contracts\RelayTransport`, with one method per
endpoint. `HttpRelayTransport` implements it over Laravel's HTTP client; `FakeRelay`
implements it in memory. The `Relay` manager and facade are a thin, typed front for
whichever is bound; the queued publish, the mail transport and the notification channel
sit on the manager. Value objects come out; arrays exist only where JSON is read or
written.

## Documentation

- **Getting started**: [Requirements](requirements.md), [Installation](getting-started/installation.md), [Quickstart](quickstart.md), [Testing](getting-started/testing.md)
- **Core concepts**: [Architecture](core-concepts/architecture.md), [Authentication](core-concepts/authentication.md), [Projects](core-concepts/projects.md), [Deliveries](core-concepts/deliveries.md), [Errors](core-concepts/errors.md), [Publishing events](core-concepts/publishing-events.md), [Webhook endpoints](core-concepts/webhook-endpoints.md), [The judge](core-concepts/judge.md), [Inbound mail](core-concepts/inbound-mail.md), [Web Push](core-concepts/web-push.md), [Outbound mail](core-concepts/outbound-mail.md), [Webhooks from Relay](core-concepts/incoming-webhooks.md), [Inbound webhooks](core-concepts/inbound-webhooks.md)
- **Cookbook**: [Register workspaces as projects](cookbook/register-workspaces-as-projects.md), [Page through the delivery log](cookbook/page-through-the-delivery-log.md), [Offer webhooks to your customers](cookbook/offer-webhooks-to-your-customers.md), [React to bounces and complaints](cookbook/react-to-bounces-and-complaints.md)
- **Configuration**: [Reference](configuration/reference.md)
- **Extension points**: [Overview](extension-points/_index.md)
- **Security**: [Threat model](security/threat-model.md)
