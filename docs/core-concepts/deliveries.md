---
title: Deliveries
description: Read the delivery log, filtered by channel, status and time, one cursor page at a time.
weight: 24
---

# Deliveries

The delivery log has one line per attempt to deliver one thing to one target.

It covers every channel: `webhook` (events out), `webhook_in`, `mail_in`, `mail_out`,
`push` and `realtime`. Statuses are `delivered`, `accepted`, `retrying` and `failed`.
Both are Relay's own strings and are passed through as they are. Which channels really
produce lines depends on what the service runs at the moment: see Relay's own status.

The fake does not write the log by itself: a publish, a mail or a push recorded on
`FakeRelay` adds no line. The log holds what the test [seeded](../getting-started/testing.md).

## Read

```php
use Cbox\Relay\Client\Data\DeliveryFilter;

$page = Relay::deliveries(
    new DeliveryFilter(channel: 'webhook', status: 'failed', from: now()->subDay(), to: now()),
    limit: 50,
);
```

`GET /v1/deliveries`. Every part of the filter is optional, and so is the filter:
`Relay::deliveries()` reads everything the key may see. `from` and `to` take any
`DateTimeInterface` and go out as ISO 8601 in UTC.

With an organization key, `Relay::forScope($ref)->deliveries()` reads one scope's log.

## Paging

`$page->items` is the list, `$page->nextCursor` the cursor for the next page (`null` on
the last), `$page->hasMore()` the same as a boolean. A `Page` is iterable and countable.
See the [recipe](../cookbook/page-through-the-delivery-log.md).

## The Delivery object

| Property | Type | |
|----------|------|-|
| `id` | `string` | `dlv_…` |
| `scopeId` | `?string` | `scp_…` |
| `channel` | `string` | Relay's string, e.g. `webhook` |
| `subjectType`, `subjectId` | `string` | what was delivered |
| `target` | `string` | where to |
| `status` | `string` | Relay's string |
| `attempt` | `int` | |
| `responseCode` | `?int` | |
| `error` | `?string` | |
| `durationMs` | `?int` | |
| `occurredAt` | `DateTimeImmutable` | |

`channel` and `status` are strings on purpose. Relay will add channels and statuses, and
an enum here would turn a new value into an error in your app.
