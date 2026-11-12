---
title: Quickstart
description: From composer require to a registered scope and a read of the delivery log.
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
$me->can('scopes:manage');      // true
```

A wrong or revoked key throws `RelayUnauthorized`.

## 3. Register a workspace as a scope

```php
$scope = Relay::scopes()->put($workspace->id, name: $workspace->name);

$scope->id;        // 'scp_…'
$scope->created;   // true the first time, false after
```

`put` is idempotent. Call it whenever a workspace is created or renamed, or every time
before you use the scope.

## 4. Read the delivery log

```php
use Cbox\Relay\Client\Data\DeliveryFilter;

$page = Relay::forScope($workspace->id)->deliveries(new DeliveryFilter(channel: 'webhook'), limit: 20);

foreach ($page as $delivery) {
    echo "{$delivery->occurredAt->format('c')} {$delivery->status} {$delivery->target}\n";
}
```

Relay does not deliver anything yet, so against the real service this page is empty. In
a test it holds what you seeded:

## 5. Fake it in a test

```php
$relay = Relay::fake();
$relay->seedDeliveryFor('ws_1', status: 'failed', responseCode: 500);

$this->get('/workspaces/ws_1/deliveries')->assertSee('500');

$relay->assertActedForScope('ws_1');
```

Next: [Testing](getting-started/testing.md), [Errors](core-concepts/errors.md).
