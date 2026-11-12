---
title: Offer webhooks to your customers
description: Declare your event types on deploy, let a workspace register its endpoint, publish from your domain code and show what became of each delivery.
weight: 33
---

# Offer webhooks to your customers

## Declare the types on deploy

The list is the whole truth, so keep it in one place and send it for every scope when
it changes.

```php
// app/Console/Commands/DeclareWebhookTypes.php
public function handle(): void
{
    Workspace::query()->each(function (Workspace $workspace): void {
        Relay::forScope($workspace->id)->eventTypes()->declare(config('webhooks.types'));
    });
}
```

A new workspace needs the same call once, right after its scope is put.

## Let a workspace register an endpoint

```php
public function store(StoreEndpointRequest $request, Workspace $workspace): JsonResponse
{
    $this->authorize('manage', $workspace);   // the client does not check who may act for a scope

    $endpoint = Relay::forScope($workspace->id)->endpoints()->register(
        $request->string('url')->toString(),
        $request->array('event_types'),
        idempotencyKey: $request->header('Idempotency-Key'),
    );

    // The secret is in this answer and never again. Show it once; do not store it.
    return response()->json(['id' => $endpoint->id, 'secret' => $endpoint->secret], 201);
}
```

A URL that is not public https, or an unknown type, is Relay's 422: catch
`RelayInvalid` and show `$e->errors`.

## Publish from your domain code

```php
DB::transaction(function () use ($order): void {
    $order->markPaid();

    Relay::forScope($order->workspace_id)->publish(
        'order.paid',
        ['id' => $order->id, 'total' => $order->total],
        idempotencyKey: "order.paid:{$order->id}",
    );
});
```

The job is dispatched after the transaction commits. If it rolls back, nothing is
published.

## Show what became of it

```php
$relay = Relay::forScope($workspace->id);

$event = $relay->events()->find($eventId);

foreach ($event?->deliveries ?? [] as $delivery) {
    // $delivery->status, ->attempts, ->lastResponseCode, ->lastError, ->nextAttemptAt
}

$relay->events()->retryDelivery($deliveryId);                 // one that failed for good
$relay->endpoints()->ping($endpointId);                       // "send a test event"
$relay->endpoints()->replay($endpointId, now()->subHours(6)); // after the customer fixed their side
```

## Test it

```php
$relay = Relay::fake()->acceptAnyScope();

$order->pay();

$relay->assertPublished('order.paid', fn (NewEvent $event, ?string $scope): bool => $event->idempotencyKey === "order.paid:{$order->id}" && $scope === $order->workspace_id);
```
