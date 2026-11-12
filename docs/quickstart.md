---
title: Quickstart
description: From composer require to a registered scope, a published event and a test that asserts it.
weight: 2
---

# Quickstart

## 1. Install

```bash
composer require cboxdk/laravel-relay-client
```

```dotenv
RELAY_URL=https://api.cboxrelay.com
RELAY_KEY=rlk_test_…
```

## 2. Check the key

```php
use Cbox\Relay\Client\Facades\Relay;

$me = Relay::whoami();

$me->organization->name;        // 'Acme'
$me->environment;               // Environment::Test
$me->can('events:publish');     // true
```

A wrong or revoked key throws `RelayUnauthorized`.

## 3. Register a workspace as a scope

```php
$scope = Relay::scopes()->put($workspace->id, name: $workspace->name);

$scope->id;        // 'scp_…'
$scope->created;   // true the first time, false after
```

`put` is idempotent. Call it whenever a workspace is created or renamed.

## 4. Declare what you publish, and where it goes

```php
$relay = Relay::forScope($workspace->id);

$relay->eventTypes()->declare(['order.paid', 'order.refunded']);   // on every deploy

$endpoint = $relay->endpoints()->register('https://customer.example/hooks', ['order.*']);
$endpoint->secret;   // given once: hand it to the customer
```

## 5. Publish

```php
Relay::forScope($workspace->id)->publish('order.paid', ['id' => $order->id, 'total' => 4200]);
```

This does not call Relay in your request. It queues a job that makes the call after the
database transaction commits and retries it; an idempotency key is generated so the
retries publish once. Run a queue worker. See
[Publishing events](core-concepts/publishing-events.md).

## 6. Fake it in a test

```php
use Cbox\Relay\Client\Data\NewEvent;

$relay = Relay::fake()->acceptAnyScope();

$this->post("/orders/{$order->id}/pay")->assertOk();

$relay->assertPublished('order.paid', fn (NewEvent $event): bool => $event->payload['id'] === $order->id);
```

Under the fake, `publish()` records at once: no queue, no HTTP.

Next: [Testing](getting-started/testing.md),
[Outbound mail](core-concepts/outbound-mail.md), [Web Push](core-concepts/web-push.md),
[Webhooks from Relay](core-concepts/incoming-webhooks.md).
