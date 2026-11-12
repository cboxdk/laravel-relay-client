---
title: Webhook endpoints
description: Declare a project's event types, register its customers' endpoints, and read events and what became of each delivery.
weight: 27
---

# Webhook endpoints

Everything here acts for one project: use `Relay::forProject($ref)` or `relay.project`.

## Event types

```php
use Cbox\Relay\Client\Data\EventType;

Relay::forProject($ref)->eventTypes()->declare([
    'order.paid',
    new EventType('order.refunded', category: 'Orders', name: 'Order refunded', example: ['id' => 'o_1']),
]);

Relay::forProject($ref)->eventTypes()->all();   // list<EventType>
```

`declare()` makes the project's types exactly the ones given: a type left out is removed.
It is safe to repeat, so run it on every deploy. A type that was not declared can be
neither published nor subscribed to. Names like Relay's own events (`mail.received` and
the like) are refused.

Needs `events:publish` to declare and `events:read` to list.

## Endpoints

```php
use Cbox\Relay\Client\Data\Envelope;

$endpoints = Relay::forProject($ref)->endpoints();

$endpoint = $endpoints->register('https://customer.example/hooks', ['order.*'], description: 'Orders');
$endpoint->secret;   // the signing secret: only on this answer. Hand it to the customer now.

$endpoints->list();                      // list<Endpoint>
$endpoints->find($id);                   // ?Endpoint
$endpoints->update($id, eventTypes: ['*'], envelope: Envelope::CloudEvents);
$endpoints->update($id, clearDescription: true);
$endpoints->pause($id);                  // events are kept and can be replayed
$endpoints->resume($id);
$endpoints->rotateSecret($id)->secret;   // the old one works until ->previousSecretExpiresAt
$endpoints->ping($id);                   // PingResult: outcome, responseCode, durationMs, error; ->succeeded()
$endpoints->replay($id, since: now()->subDay(), until: null, types: ['order.paid']);   // int: how many were queued
$endpoints->delete($id);
```

- `eventTypes` takes a type, a group (`order.*`) or `*`.
- The URL must be a public https address.
- `envelope` is `Envelope::Cbox` (Relay's default) or `Envelope::CloudEvents`.
  `signatureScheme` is `SignatureScheme::Cbox` (the default) or
  `SignatureScheme::StandardWebhooks`; it is chosen at registration and cannot be changed.
- `PingResult::$outcome` is Relay's string (`success`, `http_error`, `timeout`,
  `connection_error`, `blocked_url`, `breaker_open`, `rate_limited`, `cancelled`,
  `deferred`). `->outcome()` gives it as an `AttemptOutcome` enum, or `null` for one
  Relay added after this client was written; `->succeeded()` is true for `success`.
- `Endpoint::$status` is `active`, `paused` or `disabled`; `$endpoint->health` says how
  the receiver has been answering.

Needs `endpoints:manage`.

## Events and deliveries

```php
$events = Relay::forProject($ref)->events();

$page = $events->list(type: 'order.paid', limit: 20);   // Page<Event>, summaries, newest first
$event = $events->find($id);                             // ?Event with payload and deliveries

foreach ($event->deliveries as $delivery) {
    $delivery->endpointId; $delivery->status; $delivery->attempts; $delivery->lastResponseCode; $delivery->sequence;
}

$events->retryDelivery($delivery->id);   // queue a failed delivery again
```

A delivery's status is `pending`, `in_flight`, `delivered`, `failed`, `exhausted` or
`cancelled`. Every attempt is also a line in the [delivery log](deliveries.md)
(`channel: 'webhook'`).

Needs `events:read` to read and `events:publish` to retry.

## What a receiver gets

That is the service's side: the envelope, the signature, the retry schedule and when an
endpoint is disabled are described in Relay's own documentation. To receive Relay's
webhooks in a Laravel app, see [Webhooks from Relay](incoming-webhooks.md).
