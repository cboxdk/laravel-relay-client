---
title: Architecture
description: One transport contract with an HTTP and an in-memory implementation, a thin manager and facade on top, typed value objects throughout.
weight: 21
---

# Architecture

```
Facades\Relay ─► Relay (manager) ─► Contracts\RelayTransport ─┬─ Http\HttpRelayTransport ─► Cbox Relay
                   │                                           └─ Testing\FakeRelay (in memory)
                   ├─ Projects, EventTypes, Endpoints, Events, InboundRoutes, Inbound,
                   │  PushSubscriptions, Domains, Suppressions
                   └─ Contracts\EventPublisher ─┬─ Publishing\QueuedEventPublisher ─► Jobs\PublishEvent ─► transport
                                                └─ Publishing\ImmediateEventPublisher ─► transport

Mail\RelayMailTransport ──────────┐
Notifications\RelayWebPushChannel ┴─► Relay (manager)

Http\Middleware\VerifyRelayWebhook ─► Webhooks\WebhookSignature, Webhooks\IncomingWebhook   (no transport: Relay calls you)
```

## The contract

`Cbox\Relay\Client\Contracts\RelayTransport` has one method per endpoint of the API,
about fifty of them: `whoami`, `putProject`, `publishEvent`, `createEndpoint`, `judge`,
`sendPush`, `sendMail` and so on. Each takes typed arguments and a last
`?string $project`, which becomes the `Relay-Project` header, and returns a typed value.
A `find…` method answers `null` for a 404.

The contract passes on what it is given. It does not check that a project is set.

## The manager

`Cbox\Relay\Client\Relay` holds a transport, the project it acts for and a publisher, and
forwards to the contract. Groups of calls live on small objects it returns:
`projects()`, `eventTypes()`, `endpoints()`, `events()`, `inboundRoutes()`, `inbound()`,
`pushSubscriptions()`, `domains()` and `suppressions()`.

`forProject()` and `withoutProject()` return a new manager; the one you called them on is
unchanged. The container builds a new manager on every resolution, on whatever
transport is bound at that moment.

The manager is where a missing project is caught: see
[Authentication](authentication.md#calls-that-need-a-project).

## Publishing

`Relay::publish()` hands the event to `Contracts\EventPublisher`. The default puts a
`Jobs\PublishEvent` job on the queue; see [Publishing events](publishing-events.md).

## Value objects

Everything the client returns is a typed, readonly object in `Cbox\Relay\Client\Data`.
Arrays exist at two places only: where a JSON body is read (`fromArray`) and where one
is written (`toArray`). The free-form bags are the ones whose shape belongs to your
product: `Project::$settings`, an event's payload, a push payload.

What goes in is typed with enums where the API has a fixed set (`Envelope`,
`SignatureScheme`, `InboundRouteType`, `PushUrgency`, `SuppressionReason`, `JudgeKind`,
`Direction`). What comes out keeps statuses and kinds as Relay's own strings
(`$mail->status === 'queued'`): Relay adds values over time, and a client that refused
an unknown one would break on a new status.

A success answer the client cannot read (a missing id, a date that is not one) throws
`RelayMalformedResponse` rather than producing a half-filled object.

## What one call is

One method call is one HTTP request with the configured `timeout` and
`connect_timeout`. Nothing is retried or cached, with one exception: the queued publish
job retries on its own.
