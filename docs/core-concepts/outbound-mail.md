---
title: Outbound mail
description: Sending domains, the suppression list, Relay::sendMail() and the relay mail transport for Laravel's mailer.
weight: 31
---

# Outbound mail

> This package hands mail to Relay's API. Relay's API guide says its mail servers are
> not running yet, so today **no mail leaves Relay**: a mail is stored, filtered and
> queued, and that is where it stops. See Relay's own status for when that changes.
> Nothing on this page needs to change when it does.

## Sending domains

```php
$domains = Relay::forScope($ref)->domains();

$domain = $domains->register('acme.dk');

foreach ($domain->records as $record) {
    $record->purpose;    // 'dkim', 'spf' or 'dmarc'
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

These are the numbers Relay's automatic pause looks at, for this scope's mail from the
domain.

A domain can send once its status is `verified`. A verified domain whose record has
gone becomes `failed`. Each scope proves a domain for itself.

Needs `domains:manage`.

## The suppression list

```php
use Cbox\Relay\Client\Data\SuppressionReason;

$suppressions = Relay::forScope($ref)->suppressions();

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

$mail = Relay::forScope($ref)->sendMail(new OutgoingMail(
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

Relay::forScope($ref)->mail($mail->id);   // ?Mail, with ->recipients and ->sentAt
```

| Status | Meaning |
|--------|---------|
| `queued` | It will be signed and handed to the mail server |
| `sent` | Handed to the mail server. Not the same as delivered to an inbox |
| `blocked` | Every recipient is on the suppression list |
| `quarantined` | Held by the filter, against a threshold the scope set |
| `failed` | It could not be handed over |

Relay refuses (422, `RelayInvalid`) a sender whose domain is not a verified sending
domain of the scope, a scope that was paused for bounces or complaints, more than 50
recipients, 20 attachments or 10 MB, and a header it does not let a sender set.

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
    // 'relay' => ['transport' => 'relay', 'scope' => 'workspace-42'],   // a scope for this mailer
],
```

```dotenv
MAIL_MAILER=relay
RELAY_SCOPE=workspace-42
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

Two headers steer one send and are never passed on:

```php
$message->getHeaders()->addTextHeader('X-Relay-Scope', $workspace->id);              // send for this scope
$message->getHeaders()->addTextHeader('X-Relay-Idempotency-Key', "invoice:{$id}");   // the Idempotency-Key
```

The scope is, in order: the `X-Relay-Scope` header, the mailer's `scope`, `relay.scope`.
With none (and no `relay.scope_key`) the send throws `RelayScopeRequired`.

After the send the message carries `X-Relay-Mail-Id` and `X-Relay-Mail-Status`; read
them in a `MessageSent` listener to keep the id. A mail Relay stored but did not queue
(`blocked`, `quarantined`) is not an exception: check the status header.

The HTTP call is made where the mail is sent from. Queue your mailables
(`Mail::to(…)->queue(…)`) to keep it out of a request; a `RelayUnavailable` then
retries with the job.
