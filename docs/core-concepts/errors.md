---
title: Errors
description: Relay answers errors as RFC 9457 problem documents; the client turns each into a typed exception.
weight: 25
---

# Errors

Relay answers every error as `application/problem+json`
([RFC 9457](https://www.rfc-editor.org/rfc/rfc9457)). The client turns the status into
an exception; all of them extend `Cbox\Relay\Client\Exceptions\RelayException`.

| Answer | Exception | Carries |
|--------|-----------|---------|
| 401 | `RelayUnauthorized` | bad or revoked key |
| 403 | `RelayForbidden` | the key lacks an ability |
| 404 | `RelayNotFound` | every `find()`, `judgment()`, `mail()` and `pushMessage()` returns `null` instead; a missing *scope* (type `scope_not_found`) is still thrown |
| 409 | `RelayConflict` | idempotency key reused for a different request |
| 413 | `RelayTooLarge` | the body is too large (an event payload may be 256 KB) |
| 422 | `RelayInvalid` | `->errors` (`array<string, list<string>>`), `->errorsFor($field)` |
| 429 | `RelayRateLimited` | `->retryAfter` (seconds, or `null`) |
| 5xx, timeout, no connection | `RelayUnavailable` | the cause as `getPrevious()` for connection errors |
| any other status | `RelayException` | |
| no `RELAY_URL` or `RELAY_KEY` | `RelayNotConfigured` | nothing was sent |
| a 2xx body the client cannot read | `RelayMalformedResponse` | |
| a scoped call with no scope set | `RelayScopeRequired` | nothing was sent; see [Authentication](authentication.md#calls-that-need-a-scope) |
| an incoming webhook that does not verify | `RelayWebhookRejected` | `->reason`; see [Webhooks from Relay](incoming-webhooks.md) |

```php
use Cbox\Relay\Client\Exceptions\{RelayException, RelayInvalid, RelayRateLimited, RelayUnavailable};

try {
    Relay::scopes()->put($workspace->id, name: $workspace->name);
} catch (RelayInvalid $e) {
    $e->errorsFor('name');
} catch (RelayRateLimited $e) {
    $this->release($e->retryAfter ?? 30);
} catch (RelayUnavailable) {
    $this->release(60);
} catch (RelayException $e) {
    report($e);
}
```

## The problem document

`$e->problem` is the body Relay sent as a `Problem` (`type`, `title`, `status`, `detail`,
`errors`), and `$e->status()` its status. Both are `null` when there was no answer at
all (a connection error, an unconfigured client). An error answer that is not a problem
document, such as a proxy's HTML page, still gets a `Problem` with the status and the
type `about:blank`.

The exception message is the status, the title and the detail Relay sent. It never
contains the key.

## Branching on the type

Relay's `type` is stable and safe to branch on. It is the last part of
`$e->problem->type`, for example `missing_ability`, `scope_suspended` or
`idempotency_in_progress`:

```php
if (str_ends_with($e->problem?->type ?? '', 'scope_suspended')) { … }
```

## Retrying

One call is one request; the client does not retry it. `RelayUnavailable` and
`RelayRateLimited` are the two that may succeed later. A scope `put` is safe to repeat,
and so is any write sent with an idempotency key.

The exception is `Relay::publish()`: its queued job retries on its own. See
[Publishing events](publishing-events.md).
