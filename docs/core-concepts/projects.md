---
title: Projects
description: A project is one of your product's workspaces as Relay knows it. Put it under your own id, find it, list them.
weight: 23
---

# Projects

A project is one of your product's own workspaces (a team, a tenant, a project) as Relay
knows it. You name it with your own id, the `external_ref`; Relay gives it an `prj_…` id.

## Put

```php
$project = Relay::projects()->put($workspace->id, name: $workspace->name, settings: ['region' => 'eu']);
```

`PUT /v1/projects/{external_ref}`. It creates the project or answers the one that exists, so
it is safe to call every time.

- `$project->created` is `true` when this call created it (Relay answered 201) and
  `false` when it existed (200).
- A `name` or `settings` you leave out (`null`) is not sent.
- `settings: []` sends an empty JSON object.
- `idempotencyKey:` sends an `Idempotency-Key` header. Relay answers 409
  (`RelayConflict`) when a key is reused for a different request.

## Find

```php
Relay::projects()->find($workspace->id);   // by external ref
Relay::projects()->find('prj_…');          // or by Relay's id
```

Returns `null` when Relay answers 404.

## List

```php
$page = Relay::projects()->list(limit: 50);

while (true) {
    foreach ($page as $project) { /* … */ }

    if (! $page->hasMore()) {
        break;
    }

    $page = Relay::projects()->list(limit: 50, cursor: $page->nextCursor);
}
```

## The Project object

| Property | Type | |
|----------|------|-|
| `id` | `string` | Relay's id, `prj_…` |
| `externalRef` | `string` | your id |
| `name` | `?string` | null when the project was put without one |
| `settings` | `array<string, mixed>` | the JSON object you stored |
| `createdAt` | `DateTimeImmutable` | |
| `suspendedAt` | `?DateTimeImmutable` | `suspended()` tells whether it is set |
| `created` | `bool` | only true on the result of the `put` that created it |

The client cannot suspend, delete or otherwise change a project beyond what `put` does:
the API has no such endpoints.
