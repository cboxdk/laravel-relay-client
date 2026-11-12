---
title: Testing
description: Fake Relay in your tests with Relay::fake(), FakeRelay and the InteractsWithRelay trait, as the package's own suite does.
weight: 12
---

# Testing

`Cbox\Relay\Client\Testing\FakeRelay` is Relay in memory. It implements the transport
contract: scopes are really stored and `put` is idempotent, the delivery log holds what
you seeded and is filtered and paged, and every call is recorded. The package's own
suite runs on it; if the fake is awkward for your test, that is a bug in the fake.

## Setup

```php
use Cbox\Relay\Client\Facades\Relay;

$relay = Relay::fake();   // nothing leaves the process from here on
```

`Relay::fake()` binds the fake as `RelayTransport`, so the facade, an injected
`Cbox\Relay\Client\Relay` and an injected `RelayTransport` all reach the same fake. An
object that was built with the manager or the transport *before* `fake()` keeps the real
one, so fake first. Pass your own instance to prepare it beforehand:
`Relay::fake($prepared)`.

Or with the trait, in your `TestCase`:

```php
use Cbox\Relay\Client\Testing\InteractsWithRelay;

abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    use InteractsWithRelay;
}

$relay = $this->fakeRelay();
```

## Seeding

| Method | What it does |
|--------|--------------|
| `seedScope(string $externalRef, ?string $name = null, array $settings = [], ?DateTimeInterface $suspendedAt = null): Scope` | Stores a scope without recording a call. |
| `seedDeliveryFor(string $scope, string $status, string $channel = 'webhook', string $target = 'https://example.test/hooks', string $subjectType = 'event', ?string $subjectId = null, ?DateTimeInterface $occurredAt = null, int $attempt = 1, ?int $responseCode = null, ?string $error = null, ?int $durationMs = null): Delivery` | Adds one log line for a scope, storing the scope if it is not there. |
| `seedDeliveries(Delivery ...$deliveries): static` | Adds log lines exactly as given. |
| `actingAs(Identity $identity): static` | What `whoami` answers. The default is a test-environment organization key with `scopes:manage` and `deliveries:read`. |

```php
$relay = Relay::fake();
$relay->seedDeliveryFor('ws_1', status: 'failed', responseCode: 500, error: 'HTTP 500');

$page = Relay::forScope('ws_1')->deliveries();
```

`$status` has no default on purpose: the status values are Relay's, and the fake does
not pretend to know them.

## Assertions

| Method | What it checks |
|--------|----------------|
| `assertScopePut(string $externalRef, ?callable $where = null)` | That scope was put (by a call matching `$where`). |
| `assertScopePutTimes(string $externalRef, int $times)` | It was put exactly that often. |
| `assertScopeNotPut(string $externalRef)` | It was never put. |
| `assertNoScopePut()` | Nothing was written, whatever was read. |
| `assertDeliveriesRead(?callable $where = null)` | The delivery log was read (by a call matching `$where`). |
| `assertActedForScope(string $ref)` | At least one call sent that `Relay-Scope`. |
| `assertCalledTimes(Operation $operation, int $times)` | Exactly that many calls of one operation. |
| `assertNothingSent()` | No call at all: no read, no write. |

For your own assertions: `calls(?Operation $operation = null): list<RecordedCall>`,
`puts(string $externalRef): list<RecordedCall>`, `scopes(): list<Scope>` and
`scope(string $ref): ?Scope`. A `RecordedCall` has `operation`, `actingScope`, `ref`,
`name`, `settings`, `idempotencyKey`, `filter`, `limit` and `cursor`.

```php
$relay->assertScopePut($workspace->id, fn (RecordedCall $call): bool => $call->name === 'Acme'
    && $call->settings === ['region' => 'eu']);
```

## Making Relay misbehave

```php
use Cbox\Relay\Client\Exceptions\RelayUnavailable;

$relay->failWith(new RelayUnavailable);   // every call is recorded, then throws this
$relay->recover();                        // back to answering
```

Any `RelayException` works: `new RelayRateLimited(retryAfter: 30)`,
`new RelayForbidden`, and so on. Every exception can be built without arguments.

## Where the fake decides

Relay's API description leaves some things open. The fake picks the following; treat
them as the fake's choices, not as promises about the service.

- A list read without `limit` returns up to 50 items (`FakeRelay::DEFAULT_LIMIT`).
- A scope put without a name is named after its external ref.
- Putting an existing scope with a `name` or `settings` updates them; left out, they stay.
- A `Relay-Scope` that names no stored scope answers 404 (`RelayNotFound`).
- Acting for a scope narrows the delivery log to that scope and fills `whoami`'s
  `scope`; it does not change what the scope endpoints return.
- The delivery log is newest first. `from` and `to` include their bounds.
- An `Idempotency-Key` used again for a different put answers 409 (`RelayConflict`).
- A `limit` below 1, or a cursor the fake did not hand out, answers 422 (`RelayInvalid`).

The fake does **not** enforce abilities, does not refuse `Relay-Scope` from a scope key,
and does not refuse calls for a suspended scope. Use `failWith()` to test how your code
handles those answers.
