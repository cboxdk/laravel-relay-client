---
title: Web Push
description: The project's VAPID key, browser subscriptions, push messages and the Laravel notification channel.
weight: 30
---

# Web Push

Each project has its own VAPID key pair in Relay. Subscribers belong to the project.

## Subscribe a browser

```php
$key = Relay::forProject($ref)->pushKey();   // give it to the page as applicationServerKey
```

The page subscribes and posts `subscription.toJSON()` to your app. Hand it on:

```php
$subscription = Relay::forProject($ref)->pushSubscriptions()->subscribe($request->json()->all(), ref: "user-{$user->id}");

$subscription->id;        // 'psb_…': keep it, it is what you send to
$subscription->created;   // false when Relay knew this browser (its keys were updated)
```

`subscribe()` takes the decoded array or a `BrowserSubscription`. `ref` is your own
reference for whose browser it is. The endpoint must be https and at a push service
Relay sends to; anything else answers 422. Relay never gives the endpoint back, only
its host (`$subscription->service`).

```php
Relay::forProject($ref)->pushSubscriptions()->list(ref: "user-{$user->id}");   // list<PushSubscription>, the newest 500
Relay::forProject($ref)->pushSubscriptions()->unsubscribe($id);
```

## Send

```php
use Cbox\Relay\Client\Data\PushUrgency;

$message = Relay::forProject($ref)->push(
    $subscription->id,
    ['title' => 'Your order shipped', 'url' => '/orders/1'],   // what your service worker receives
    ttl: 3600,
    urgency: PushUrgency::Normal,
    topic: 'orders',
);

$message->status;                                    // 'queued'
Relay::forProject($ref)->pushMessage($message->id);    // ?PushMessage: status, attempts, responseCode, error
```

Relay stores and queues the message and sends it from its own queue. A payload may be
3000 bytes. `delivered` means the push service took the message; whether the device
showed it nobody is told. `expired` means the subscription is gone and Relay removed
it: ask the browser to subscribe again.

`push()` makes one HTTP call where it is called. Call it from a queued job to keep it
out of a request.

## The notification channel

```php
use Cbox\Relay\Client\Notifications\RelayWebPushChannel;
use Cbox\Relay\Client\Notifications\WebPushMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class OrderShipped extends Notification implements ShouldQueue
{
    public function via(object $notifiable): array
    {
        return [RelayWebPushChannel::class];
    }

    public function toRelayWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(['title' => 'Your order shipped'], ttl: 3600, topic: 'orders');
    }
}
```

The notifiable says which subscriptions are its own:

```php
public function routeNotificationForRelayWebPush(Notification $notification): array
{
    return $this->pushSubscriptions()->pluck('relay_id')->all();   // one id or several
}
```

- Each subscription gets its own message.
- The project is the client's default (`relay.project`) unless the message names one:
  `new WebPushMessage($payload, project: $workspace->id)`.
- A subscription Relay no longer knows (404) is skipped, so the others still get
  theirs. Every other failure is thrown, so a queued notification is retried.
- `send()` returns the `PushMessage`s Relay stored.
- On demand: `Notification::route(RelayWebPushChannel::ROUTE, $id)->notify(…)`.

To clean up expired subscriptions, subscribe an endpoint to `push.expired`: see
[Webhooks from Relay](incoming-webhooks.md).

Needs `push:manage` for the key and subscriptions and `push:send` to send.
