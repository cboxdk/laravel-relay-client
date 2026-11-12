---
title: Local delivery status
description: The optional store that keeps a row per mail sent through Relay and a row per webhook event about it, in your own database.
weight: 34
---

# Local delivery status

Relay knows what happened to every mail, and `Relay::mail($id)` asks it. The store keeps
the same answer in your own database, so a support page or a report does not call Relay
for every row.

It is off by default. With it off the package keeps nothing and needs no database.

## Turn it on

```bash
php artisan relay:install --store   # publishes the migration
php artisan migrate
```

```dotenv
RELAY_STORE=true
```

The migration is published, never loaded by the package: the two tables exist only in an
app that asked for them. `relay.store.connection` puts them on another database
connection.

## What is kept

**`relay_messages`**, one row per mail (`Models\RelayMessage`):

| Column | What it is |
|--------|------------|
| `id` | Relay's id of the mail (`msg_…`). |
| `connection`, `project` | The Relay connection and the project the mail was sent for. |
| `subject` | The subject's SHA-256, the subject itself, or nothing: `relay.store.subject` (`hash`, `plain`, `none`). `hash` is the default. |
| `mailable` | The mailable or notification class, for mail sent through the `relay` transport. |
| `from_address`, `recipients` | The sender, and how many recipients (to, cc and bcc). |
| `status` | The last thing known: what Relay answered (`queued`, `blocked`, `quarantined`), then `delivered`, `bounced` or `complained`. |
| `delivered`, `bounced`, `complained`, `clicked` | How many events of each kind arrived. |
| `last_event_at` | When the newest event happened. |

**`relay_message_events`**, one row per webhook delivery about a mail
(`Models\RelayMessageEvent`): `delivery_id` (unique), `event_id`, `message_id`, `type`,
`recipient`, `data` (the event's data as Relay sent it) and `occurred_at`.

A row is written when `Relay::sendMail()` returns, which is also what the `relay` mail
transport calls. Events are written by [the package's webhook route](incoming-webhooks.md#the-packages-route-and-laravel-events):
subscribe your endpoint to `mail.*` and register the route, or the rows never change.

```php
use Cbox\Relay\Client\Models\RelayMessage;

$message = RelayMessage::query()->with('events')->find($mailId);

$message->status;                          // 'bounced'
$message->events->pluck('type');           // ['mail.delivered', 'mail.bounced']
RelayMessage::query()->where('subject', hash('sha256', 'Your invoice'))->latest()->get();
```

## What to know before relying on it

- **Idempotent.** A mail id is one row however often it is recorded. A webhook delivery
  is one event row, so Relay's retries and replays never count twice.
- **`status` only gets worse.** A `delivered` after a `bounced` leaves `bounced`; a
  `complained` outranks both. One mail has several recipients, so `status` answers "is
  there anything to look at", and the counters and the event rows answer the rest.
- **Events for mail this app did not send are kept too** (mail sent with the same key
  from another app): an event row with no `relay_messages` row.
- **The subject of a mail is personal data when it names somebody.** That is why the
  default is a hash. The event rows keep recipients' addresses in clear text; remove
  rows on your own schedule, the package has no retention.
- **Not kept:** the mail's body, its recipients' addresses on the mail row, and events
  about anything but outbound mail (`push.*`, `mail.received`, `endpoint.disabled`).
- **It follows, it does not lead.** If your endpoint is disabled or the route is not
  registered, the rows stop changing. Relay stays the source of truth: `Relay::mail($id)`.

## Replace the store

Bind your own `Cbox\Relay\Client\Contracts\MessageStore` to keep the rows elsewhere. In
a test, bind `Testing\FakeMessageStore` to see what would be kept without a database.
