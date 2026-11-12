---
title: React to bounces and complaints
description: Receive Relay's mail events on a verified route and update your own records.
weight: 34
---

# React to bounces and complaints

Relay puts a bounced or complaining address on the scope's suppression list by itself.
What it cannot do is tell your own data: the contact you should stop mailing, the user
you should show a warning to. For that, receive its events.

## Subscribe an endpoint of your own

Once per scope:

```php
$endpoint = Relay::forScope($workspace->id)->endpoints()->register(
    route('hooks.relay'),
    ['mail.bounced', 'mail.complained', 'scope.outbound_paused'],
);

// $endpoint->secret goes into RELAY_WEBHOOK_SECRET (or your own store, per scope).
```

## Receive

```php
use Cbox\Relay\Client\Webhooks\Events\MailBounced;
use Cbox\Relay\Client\Webhooks\Events\MailComplained;
use Cbox\Relay\Client\Webhooks\Events\ScopeOutboundPaused;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

Route::post('/hooks/relay', function (Request $request) {
    $webhook = IncomingWebhook::from($request);

    // The same delivery can arrive twice. deliveryId is unique per event and endpoint.
    if (! Cache::add("relay-delivery:{$webhook->deliveryId}", true, now()->addDays(3))) {
        return response()->noContent();
    }

    $event = $webhook->event();

    match (true) {
        $event instanceof MailBounced && $event->permanent => Contact::where('email', $event->recipient)->update(['undeliverable_at' => now()]),
        $event instanceof MailComplained => Contact::where('email', $event->recipient)->update(['unsubscribed_at' => now()]),
        $event instanceof ScopeOutboundPaused => report(new OutboundPaused($event->scopeId, $event->reason)),
        default => null,
    };

    return response()->noContent();
})->middleware('relay.webhook')->name('hooks.relay');
```

`$event->scopeId` is Relay's id (`scp_…`), not your external ref. Keep the `Scope::$id`
that `scopes()->put()` returned if you need to map it back to a workspace.

## Test it

```php
use Cbox\Relay\Client\Webhooks\WebhookSignature;

config()->set('relay.webhook.secret', 'test-secret');

$body = json_encode([
    'id' => 'evt_1', 'type' => 'mail.bounced', 'delivery_id' => 'd_1',
    'data' => ['id' => 'msg_1', 'scope_id' => 'scp_1', 'recipient' => 'ann@example.com', 'permanent' => true, 'status' => '5.1.1', 'diagnostic' => '550 no such user'],
]);

$this->call('POST', '/hooks/relay', server: ['HTTP_X_CBOX_SIGNATURE' => WebhookSignature::header($body, 'test-secret'), 'CONTENT_TYPE' => 'application/json'], content: $body)
    ->assertNoContent();

expect(Contact::firstWhere('email', 'ann@example.com')->undeliverable_at)->not->toBeNull();
```
