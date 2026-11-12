---
title: Publishing events
description: Relay::publish() queues a job that makes the HTTP call after commit, with an idempotency key so retries publish once; publishNow and publishBatch call inline; a subject makes an event erasable.
weight: 26
---

# Publishing events

An event you publish is delivered by Relay to every webhook endpoint of the scope that
subscribes to its type: signed, in order per endpoint, retried for about two days.

```php
use Cbox\Relay\Client\Facades\Relay;

Relay::forScope($workspace->id)->publish('order.paid', ['id' => $order->id, 'total' => 4200]);
```

The type must be one the scope [declared](webhook-endpoints.md#event-types). A payload
may be 256 KB.

## publish() does not call Relay in your request

`publish()` dispatches a queued job, `Cbox\Relay\Client\Jobs\PublishEvent`, and returns.
The job makes the HTTP call. So a slow or unreachable Relay never slows a request down,
and an event is not lost because Relay was down for a minute.

- **After commit.** The job is dispatched after the surrounding database transaction
  commits. An event published inside a transaction that rolls back is never sent.
- **Once.** An event without an idempotency key gets one when it is dispatched
  (`pub_` and 32 hex characters), and the job carries it. However often the job runs,
  Relay stores the event once. `publish()` returns the key.
- **Your own key.** Pass one when the same fact may be published from more than one
  place: `publish('order.paid', $payload, idempotencyKey: "order.paid:{$order->id}")`.
- **When it occurred.** `occurredAt:` takes any `DateTimeInterface`. Left out, the
  client stamps the moment `publish()` was called. Relay would otherwise use the time
  it received the event, which is when the job ran.
- **Who it is about.** `subject:` is described [below](#forgetting-a-person).

A worker must be running for the connection and queue the job goes on. Without one,
nothing is published.

### What the job does with an answer

| Relay answers | The job |
|---------------|---------|
| 202 | is done |
| 403, 404, 413, 422 | fails at once, without retrying: sending it again will not help |
| 429 | is released for as long as `Retry-After` asks (or its own backoff when Relay names none) |
| 5xx, a timeout, no connection, anything else | is retried with the backoff |

It is attempted `relay.publish.tries` times in all (5), waiting
`relay.publish.backoff` seconds before the second attempt, the third and so on
(10, 30, 120, 600; the last value repeats). A job that fails lands in your
`failed_jobs` like any other; `queue:retry` publishes it with the same key.

A 422 here is usually a type the scope did not declare, a 403 a suspended scope or a
key without `events:publish`, a 404 a scope Relay does not know.

### Configuration

| Key | Env | Default | |
|-----|-----|---------|---|
| `relay.publish.sync` | `RELAY_PUBLISH_SYNC` | `false` | `true` calls Relay inside `publish()` instead of queueing |
| `relay.publish.connection` | `RELAY_PUBLISH_CONNECTION` | app default | queue connection of the job |
| `relay.publish.queue` | `RELAY_PUBLISH_QUEUE` | connection default | queue of the job |
| `relay.publish.tries` | `RELAY_PUBLISH_TRIES` | `5` | attempts in all |
| `relay.publish.backoff` | none | `[10, 30, 120, 600]` | seconds before each retry |

With `sync` the call is made in the caller's process and its failure is thrown there;
nothing is retried.

## publishNow()

```php
$published = Relay::forScope($workspace->id)->publishNow('order.paid', $payload, "order.paid:{$order->id}");

$published->id;           // the event's id
$published->deliveries;   // how many endpoints it was queued for
$published->duplicate;    // true when the idempotency key was seen before
```

One HTTP call, now, whatever `relay.publish.sync` says. Exceptions are yours to handle.
No idempotency key is generated here: without one, calling it twice publishes twice.

## publishBatch()

```php
use Cbox\Relay\Client\Data\NewEvent;

$published = Relay::forScope($workspace->id)->publishBatch([
    new NewEvent('order.paid', ['id' => 1], idempotencyKey: 'order.paid:1'),
    new NewEvent('order.paid', ['id' => 2], idempotencyKey: 'order.paid:2'),
]);   // list<Published>, in the same order
```

Up to 100 events in one call, made now. All are stored or none. It is not queued:
dispatch your own job around it when it should be.

## Forgetting a person

Give an event a `subject` when you publish it: your own id for the person it is about,
not a name or an address.

```php
Relay::forScope($ref)->publish('order.paid', $payload, subject: "contact_{$contact->id}");
```

It is stored with the event and never sent to a receiver. `publish()`, `publishNow()`
and `NewEvent` all take it, as the last argument, and the queued job carries it. A
subject is 1 to 191 visible ASCII characters without spaces; anything else throws
`RelayInvalid` where you call `publish()`, before a job is queued for it.

When that person must be erased:

```php
$erasure = Relay::forScope($ref)->eraseSubject("contact_{$contact->id}");

$erasure->removed;   // rows that went: events, their deliveries and their attempts
```

It removes their events in the scope. Calling it again removes nothing. An event
published without a subject is not found: it stays until the scope's retention removes
it. The delivery log keeps its rows (a target, a status and a time, no content) until
its own retention. Needs `events:publish`.

This erases what Relay holds about the events. What your customers' endpoints already
received is theirs.

## Replace how publish() hands over

`publish()` goes through `Cbox\Relay\Client\Contracts\EventPublisher`. Bind your own to
write to an outbox table or to use a different bus; see
[Extension points](../extension-points/_index.md#replace-the-publisher).
