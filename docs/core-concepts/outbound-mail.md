---
title: Outbound mail
description: Sending domains, click tracking, the suppression list, Relay::sendMail() and the relay mail transport for Laravel's mailer.
weight: 31
---

# Outbound mail

> This package hands mail to Relay's API; Relay's own mail servers do the delivering.
> The package's tests run against the API description and the fake, not against a
> running Relay.

## Sending domains

```php
$domains = Relay::forProject($ref)->domains();

$domain = $domains->register('acme.dk');

foreach ($domain->records as $record) {
    $record->purpose;    // 'dkim', 'spf', 'dmarc', or 'tracking' (see click tracking below)
    $record->type; $record->name; $record->value;
    $record->required;   // only the DKIM record is
}

$domains->verify($domain->id)->verified();   // looks the DKIM record up now
$domains->list();  $domains->find($id);  $domains->remove($id);
```

```php
$reputation = $domains->reputation($domain->id);

$reputation->sent; $reputation->bounced; $reputation->complained;   // recipients, in the last ->windowDays days
$reputation->bounceRate; $reputation->complaintRate;                // null when nothing was sent
```

These are the numbers Relay's automatic pause looks at, for this project's mail from the
domain.

A domain can send once its status is `verified`. A verified domain whose record has
gone becomes `failed`. Each project proves a domain for itself.

Needs `domains:manage`.

## Click tracking

Off until you turn it on. With it on, the `http` and `https` links in the HTML of a
mail go through Relay, which records the click and sends the reader on to the address
you wrote. Opens are not tracked.

```php
use Cbox\Relay\Client\Data\TrackingChanges;

$domain = $domains->trackClicks($domain->id);          // on for every mail from the domain
$domain = $domains->trackClicks($domain->id, false);   // off again

$domain->tracking->clicks;   // bool
$domain->tracking->host;     // the domain's own host for the links, or null for Relay's shared host
```

A mail can say otherwise, whatever its domain says. Left out, the domain decides:

```php
new OutgoingMail(from: …, to: …, subject: …, html: …, trackClicks: true);

$mail->tracksClicks;   // the answer the mail was accepted with
```

A host of your own for the links is a CNAME to Relay's tracking host. Name it, publish
the record with the purpose `tracking` from the answer, and verify the domain again:

```php
$domain = $domains->tracking($domain->id, new TrackingChanges(clicks: true, host: 'links.acme.dk'));
$domain = $domains->tracking($domain->id, new TrackingChanges(clearHost: true));   // back to the shared host
```

Only what you set in `TrackingChanges` is sent, and only that changes. Whether links
are actually written on your own host is Relay's decision; until it serves customers'
hosts, links use its shared host even when your record is in place.

What to know before you rely on it:

- A click names the mail and the link, **not the recipient**: every recipient of a mail
  gets the same link. Send one mail per recipient when you need to know who clicked.
- Only links in the HTML part are rewritten. The text part, `mailto:` and `tel:` links,
  the address in `List-Unsubscribe`, and a link with the attribute
  `data-relay-track="off"` are left alone.
- A mail scanner that follows every link looks like a click. Relay reports the same link
  at most once a minute.
- The click arrives as the event [`mail.clicked`](incoming-webhooks.md).

Changing a domain's tracking needs `domains:manage`.

## The suppression list

```php
use Cbox\Relay\Client\Data\SuppressionReason;

$suppressions = Relay::forProject($ref)->suppressions();

$suppressions->add('ann@example.com', SuppressionReason::Unsubscribe)->created;   // false when it was listed already
$suppressions->list(limit: 50);   // Page<Suppression>: address, reason, source, createdAt
$suppressions->remove('ann@example.com');
```

An address on the list is never sent to. Adding it twice keeps its first reason. Relay
adds bounced and complaining addresses by itself.

Needs `suppressions:manage`.

## Send

```php
use Cbox\Relay\Client\Data\MailAddress;
use Cbox\Relay\Client\Data\MailAttachment;
use Cbox\Relay\Client\Data\OutgoingMail;

$mail = Relay::forProject($ref)->sendMail(new OutgoingMail(
    from: new MailAddress('hello@acme.dk', 'Acme'),
    to: ['ann@example.com'],
    subject: 'Your order',
    html: '<p>Thanks for your order.</p>',
    text: 'Thanks for your order.',
    replyTo: 'support@acme.dk',
    headers: ['X-Order' => 'o_1'],
    attachments: [new MailAttachment('receipt.pdf', 'application/pdf', $pdfBytes)],
), idempotencyKey: "order-mail:{$order->id}");

$mail->id;           // 'msg_…'
$mail->status;       // 'queued', 'blocked' or 'quarantined' right after sending
$mail->accepted();   // queued or sent
$mail->reason;       // why it was held, blocked or failed

Relay::forProject($ref)->mail($mail->id);   // ?Mail, with ->recipients and ->sentAt
```

| Status | Meaning |
|--------|---------|
| `queued` | It will be signed and handed to the mail server |
| `sent` | Handed to the mail server. Not the same as delivered to an inbox |
| `blocked` | Every recipient is on the suppression list |
| `quarantined` | Held by the filter, against a threshold the project set |
| `failed` | It could not be handed over |

Relay refuses (422, `RelayInvalid`) a sender whose domain is not a verified sending
domain of the project, a project that was paused for bounces or complaints, more than 50
recipients, 20 attachments or 10 MB, and a header it does not let a sender set.

### A held mail

A mail the filter held has the status `quarantined` and a `reason`. Look at it, then
decide:

```php
use Cbox\Relay\Client\Exceptions\RelayConflict;

$mail = Relay::forProject($ref)->releaseMail($id);   // send it after all: 'queued'
$mail = Relay::forProject($ref)->discardMail($id);   // never send it: 'blocked', its content is removed

try {
    Relay::forProject($ref)->releaseMail($id);
} catch (RelayConflict $e) {
    $e->type() === 'mail_not_held';   // someone decided already, or it was never held
}
```

A release queues the mail as if it had not been held; recipients suppressed since are
left out. Both calls answer the `Mail` and throw `RelayConflict` with the type
`mail_not_held` when its status is not `quarantined`, so the second of two people
deciding hears about it. A mail the project does not have throws `RelayNotFound`.

A held mail nobody decides on keeps its content for 30 days like any other and then
stays as it is, unsent. That is Relay's retention, not this package's.

### Headers

A sender may set any `X-` header and `List-Unsubscribe`, `List-Unsubscribe-Post`,
`List-Id`, `In-Reply-To`, `References`, `Precedence` and `Auto-Submitted`.
`OutgoingMail::allowsHeader($name)` answers for one name. `sendMail()` sends what you
give it, so a header outside that set is Relay's 422; the mail transport below drops
such headers instead.

Needs `mail:send`.

## The mail transport

The package registers a `relay` transport with Laravel's mail manager.

```php
// config/mail.php
'mailers' => [
    'relay' => ['transport' => 'relay'],
    // 'relay' => ['transport' => 'relay', 'project' => 'workspace-42'],   // a project for this mailer
],
```

```dotenv
MAIL_MAILER=relay
RELAY_PROJECT=workspace-42
```

Every mail the mailer sends becomes one `POST /v1/mail`. The Symfony `Email` is mapped
like this:

| From the mail | Goes out as |
|---------------|-------------|
| the first From address and its name | `from` |
| To, Cc, Bcc | `to`, `cc`, `bcc`, addresses only |
| the first Reply-To address | `reply_to` |
| Subject, HTML body, text body | `subject`, `html`, `text` |
| headers Relay allows (above) | `headers`, each value on one line |
| attachments | `attachments`, base64 |

What the API has no place for is dropped, not sent: display names of recipients, a
second Reply-To, every header outside the allowed set (`Sender`, `Return-Path`, custom
ones without `X-`), and the inline placement of an embedded image, which goes as an
ordinary attachment.

Three headers steer one send and are never passed on:

```php
$message->getHeaders()->addTextHeader('X-Relay-Project', $workspace->id);              // send for this project
$message->getHeaders()->addTextHeader('X-Relay-Idempotency-Key', "invoice:{$id}");   // the Idempotency-Key
$message->getHeaders()->addTextHeader('X-Relay-Track-Clicks', 'false');              // click tracking for this mail alone
```

`X-Relay-Track-Clicks` takes `true` or `false` (also `1`/`0`, `yes`/`no`, `on`/`off`).
Anything else throws a `TransportException` and nothing is sent. Without the header the
sending domain's setting decides.

The project is, in order: the `X-Relay-Project` header, the mailer's `project`, `relay.project`.
With none (and no `relay.project_key`) the send throws `RelayProjectRequired`.

After the send the message carries `X-Relay-Mail-Id` and `X-Relay-Mail-Status`; read
them in a `MessageSent` listener to keep the id. A mail Relay stored but did not queue
(`blocked`, `quarantined`) is not an exception: check the status header.

The HTTP call is made where the mail is sent from. Queue your mailables
(`Mail::to(…)->queue(…)`) to keep it out of a request; a `RelayUnavailable` then
retries with the job.
