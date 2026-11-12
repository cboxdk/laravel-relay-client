---
title: Scopes
description: A scope is one of your product's workspaces as Relay knows it. Put it under your own id, find it, list them.
weight: 23
---

# Scopes

A scope is one of your product's own workspaces (a team, a tenant, a project) as Relay
knows it. You name it with your own id, the `external_ref`; Relay gives it an `scp_…` id.

## Put

```php
$scope = Relay::scopes()->put($workspace->id, name: $workspace->name, settings: ['region' => 'eu']);
```

`PUT /v1/scopes/{external_ref}`. It creates the scope or answers the one that exists, so
it is safe to call every time.

- `$scope->created` is `true` when this call created it (Relay answered 201) and
  `false` when it existed (200).
- A `name` or `settings` you leave out (`null`) is not sent.
- `settings: []` sends an empty JSON object.
- `idempotencyKey:` sends an `Idempotency-Key` header. Relay answers 409
  (`RelayConflict`) when a key is reused for a different request.

## Find

```php
Relay::scopes()->find($workspace->id);   // by external ref
Relay::scopes()->find('scp_…');          // or by Relay's id
```

Returns `null` when Relay answers 404.

## List

```php
$page = Relay::scopes()->list(limit: 50);

while (true) {
    foreach ($page as $scope) { /* … */ }

    if (! $page->hasMore()) {
        break;
    }

    $page = Relay::scopes()->list(limit: 50, cursor: $page->nextCursor);
}
```

## The Scope object

| Property | Type | |
|----------|------|-|
| `id` | `string` | Relay's id, `scp_…` |
| `externalRef` | `string` | your id |
| `name` | `string` | |
| `settings` | `array<string, mixed>` | the JSON object you stored |
| `createdAt` | `DateTimeImmutable` | |
| `suspendedAt` | `?DateTimeImmutable` | `suspended()` tells whether it is set |
| `created` | `bool` | only true on the result of the `put` that created it |

The client cannot suspend, delete or otherwise change a scope beyond what `put` does:
the API has no such endpoints yet.
