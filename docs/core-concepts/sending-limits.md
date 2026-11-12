---
title: Sending limits and holds
description: How much a project may send, asking for more, what a refusal for a limit looks like, and mail that Relay's own guard holds.
weight: 37
---

# Sending limits and holds

Relay keeps one sender from getting the shared sending addresses blocked for everybody.
Two things of that reach your app: a **limit** on how many recipients a project may send
to, and a **hold** on a mail Relay doubts.

## The limits

A project has two limits, both in recipients: per hour and per day. Relay sets them once
a day from the project's own normal use, with more room for a sender whose mail is
delivered. An organization that Relay trusts to make one project per customer has a limit
of its own on top, for all its projects together.

```php
use Cbox\Relay\Client\Facades\Relay;

$limits = Relay::forProject($ref)->sendingLimits()->current();

$limits->hour->limit;       // recipients an hour
$limits->hour->used;        // sent to in the last hour
$limits->hour->origin;      // 'default', 'auto' (Relay's daily look), 'request' or 'operator'
$limits->day->remaining();  // what is left of the day
$limits->remaining();       // what is left of the tighter of the two
$limits->standing;          // 'good', 'fair', 'new', 'poor'; null until Relay has looked
$limits->openRequest;       // the request that waits for an answer, if any
$limits->changes;           // the last ten moves, newest first
```

The key needs the ability `limits:request` for everything on this page. A key that only
sends cannot raise its own limits.

## When a limit is reached

The mail is refused and not stored. `sendMail()` throws `RelayRateLimited`:

```php
use Cbox\Relay\Client\Exceptions\RelayRateLimited;

try {
    Relay::forProject($ref)->sendMail($mail);
} catch (RelayRateLimited $e) {
    if ($e->sendingLimited()) {
        $e->scope();        // 'project', or 'organization' for the limit all its projects share
        $e->retryAfter;     // seconds until there is room; send the mail again then
    }
}
```

`sendingLimited()` tells this apart from an ordinary rate limit on the key, which is about
how often you call and not about how much you send. In a batch the mails that do not fit
come back as their own `429` result and the rest are accepted. Relay also sends the event
`project.outbound_limited`, once an hour at most.

Do not retry at once: wait for `retryAfter`, and if the limit keeps being reached, ask
for more.

## Asking for more

```php
$request = Relay::forProject($ref)->sendingLimits()->request(
    hour: 1500,
    day: 12000,
    reason: 'Invoices and receipts to our own customers, about 9,000 a day at month end.',
);

$request->approved();   // granted at once
$request->pending();    // waits for Relay's operator
```

Neither number may be under the current limit, one must be over it, and the day must be
at least the hour. The reason is 10 to 500 characters and is read by a person. Relay
grants at once when the project is in good standing and asks for no more than a few times
what it has sent at its busiest; anything else waits for Relay's operator, who may grant
other numbers than you asked for:

```php
$request = Relay::forProject($ref)->sendingLimits()->findRequest($id);

$request->decision?->hour;       // what was granted; null when declined
$request->decision?->reason;
$request->decision?->decidedBy;  // 'auto' or 'operator'

Relay::forProject($ref)->sendingLimits()->requests();   // newest first, at most 50
```

One request per project can be open. A second throws `RelayConflict` with the type
`limit_request_open`. The answer also arrives as the event
`project.limit_request_decided`, and every move of the limits as
`project.outbound_limits_changed`.

## A mail Relay holds

A held mail has the status `quarantined`. `Mail::$heldBy` says who holds it:

- `project`: a filter threshold of the project's own. It may release or discard the mail,
  as described under [outbound mail](outbound-mail.md).
- `relay`: Relay's own guard. The project can discard it; only Relay's operator lets it
  go, or Relay's second look when it finds nothing wrong.

```php
use Cbox\Relay\Client\Exceptions\RelayForbidden;

$mail = Relay::forProject($ref)->mail($id);

if ($mail->heldByRelay()) {
    // Nothing to do but wait or discard. A release is refused:
    try {
        Relay::forProject($ref)->releaseMail($id);
    } catch (RelayForbidden $e) {
        $e->heldByRelay();   // true: 'mail_held_by_relay'
    }
}
```

The events say the same: `mail.blocked` carries `state` (`held` or `blocked`) and
`heldBy`, `mail.released` says who let a mail go (`project`, `operator` or `relay`) and
`mail.discarded` that it never will be sent. Several held mails in an hour pause the
project (`project.outbound_paused`, with `cause`), and a project that sends unlike itself
is watched first (`project.outbound_watched`).

## Finding the mail again

A mail keeps the idempotency key it was accepted with, and its metadata:

```php
use Cbox\Relay\Client\Data\MailFilter;

Relay::forProject($ref)->mails(MailFilter::idempotencyKey('order-7'));
Relay::forProject($ref)->mails(MailFilter::metadata('order', '7'));   // one pair, compared exactly
```

When a retry renders slightly different content under the same key, Relay answers `409`
`idempotency_key_reused`. `RelayConflict::mailId()` is then the mail the first call
accepted, so you can see that it left instead of failing blind.

## A product that serves many customers

One project per customer, trust and the organization's limit, one webhook endpoint per
project and limit requests on a customer's behalf are described in Relay's own guide,
`docs/api/products.md` in Relay's documentation.
