---
title: Introduction
description: The Laravel client for Cbox Relay. Who am I, scopes and the delivery log, with an in-memory fake.
weight: 1
---

# Cbox Relay client

Cbox Relay is the service that delivers webhooks, mail and push for Cbox products. This
package is how a Laravel app talks to its API. It is an ordinary customer of Relay: an
API key, the public endpoint, nothing else.

## What it does today

- **[Authentication and acting for a scope](core-concepts/authentication.md)**:
  `Relay::whoami()`, the bearer key, and `Relay::forScope()` for an organization key that
  acts for one of its scopes.
- **[Scopes](core-concepts/scopes.md)**: `Relay::scopes()->put()`, `find()` and `list()`.
  A product registers each of its own workspaces under its own id.
- **[The delivery log](core-concepts/deliveries.md)**: `Relay::deliveries()`, filtered
  and cursor-paginated.
- **[Typed errors](core-concepts/errors.md)**: every problem answer is its own exception.
- **[A fake](getting-started/testing.md)**: `Relay::fake()` keeps scopes and deliveries
  in memory.

## What it does not do yet

Relay itself is being built in milestones, and the client follows it.

- **Publishing events** (webhooks out): not yet. It comes with the next milestone of
  the service.
- **Sending mail, a mail driver, push**: not yet. It comes with a later milestone.

Until then there is nothing to deliver, so the delivery log of a real Relay is empty.
The log API is here so the screens that read it can be built and tested against the fake.

## The mental model

There is one contract, `Cbox\Relay\Client\Contracts\RelayTransport`, with one method per
endpoint. `HttpRelayTransport` implements it over Laravel's HTTP client; `FakeRelay`
implements it in memory. The `Relay` manager and facade are a thin, typed front for
whichever is bound. Value objects come out; arrays exist only where JSON is read or
written.

## Documentation

- **Getting started**: [Requirements](requirements.md), [Installation](getting-started/installation.md), [Quickstart](quickstart.md), [Testing](getting-started/testing.md)
- **Core concepts**: [Architecture](core-concepts/architecture.md), [Authentication](core-concepts/authentication.md), [Scopes](core-concepts/scopes.md), [Deliveries](core-concepts/deliveries.md), [Errors](core-concepts/errors.md)
- **Cookbook**: [Register workspaces as scopes](cookbook/register-workspaces-as-scopes.md), [Page through the delivery log](cookbook/page-through-the-delivery-log.md)
- **Extension points**: [Overview](extension-points/_index.md)
- **Security**: [Threat model](security/threat-model.md)
