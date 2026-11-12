---
title: Know what happened to a mail
description: From a sent Laravel mail to its delivery, bounce or click, with the local store or by asking Relay.
weight: 35
---

# Know what happened to a mail

A customer says the invoice never arrived. Three ways to answer, from least to most
set up.

## 1. Ask Relay, with the id you kept

The `relay` mail transport puts Relay's id on the sent message. Keep it next to your
own record:

```php
use Cbox\Relay\Client\Mail\RelayMailTransport;
use Illuminate\Mail\Events\MessageSent;

Event::listen(function (MessageSent $event): void {
    $id = $event->message->getHeaders()->get(RelayMailTransport::MAIL_ID_HEADER)?->getBodyAsString();

    if ($id !== null && isset($event->data['invoice'])) {
        $event->data['invoice']->update(['relay_mail_id' => $id]);
    }
});
```

```php
$mail = Relay::mail($invoice->relay_mail_id);

foreach ($mail?->recipients ?? [] as $recipient) {
    echo "{$recipient->address}: {$recipient->status} {$recipient->detail}\n";
}
```

One call to Relay per look. Fine for a support page, not for a list of a thousand.

## 2. Hear it as it happens

Register an endpoint for `mail.*`, register the package's route, and listen:

```php
// routes/web.php
Relay::webhookRoutes();          // POST /relay/webhook
```

```php
use Cbox\Relay\Client\Webhooks\Received\MailBounced;
use Cbox\Relay\Client\Webhooks\Received\MailDelivered;

Event::listen(function (MailDelivered $event): void {
    Invoice::where('relay_mail_id', $event->event->id)->update(['delivered_at' => $event->webhook->occurredAt]);
});

Event::listen(function (MailBounced $event): void {
    Invoice::where('relay_mail_id', $event->event->id)->update(['bounce' => $event->event->diagnostic]);
});
```

`delivered` means the recipient's mail server took the mail. It does not say inbox or
junk.

## 3. Keep it locally

Turn on [the local store](../core-concepts/delivery-status.md) and the package does the
keeping: a row per mail, a row per event.

```php
use Cbox\Relay\Client\Models\RelayMessage;

$message = RelayMessage::query()->with('events')->find($invoice->relay_mail_id);

$message?->status;                                  // 'delivered', 'bounced', …
$message?->events->where('type', 'mail.bounced');   // each with `recipient` and `data`

// Everything that bounced this week, with no call to Relay:
RelayMessage::query()->where('status', 'bounced')->where('created_at', '>', now()->subWeek())->get();
```

## When the rows say nothing

`php artisan relay:doctor` tells whether the route is registered and the secret is set.
`php artisan relay:tail --channel=webhook --status=failed` shows whether Relay's
deliveries to your endpoint are failing. An endpoint that fails for too long is switched
off (`endpoint.disabled`), and the rows stop changing until it is resumed and replayed.
