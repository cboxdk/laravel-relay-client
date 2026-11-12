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
| 404 | `RelayNotFound` | `scopes()->find()` returns `null` instead |
| 409 | `RelayConflict` | idempotency key reused for a different request |
| 422 | `RelayInvalid` | `->errors` (`array<string, list<string>>`), `->errorsFor($field)` |
| 429 | `RelayRateLimited` | `->retryAfter` (seconds, or `null`) |
| 5xx, timeout, no connection | `RelayUnavailable` | the cause as `getPrevious()` for connection errors |
| any other status | `RelayException` | |
| no `RELAY_URL` or `RELAY_KEY` | `RelayNotConfigured` | nothing was sent |
| a 2xx body the client cannot read | `RelayMalformedResponse` | |

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

## Retrying

The client does not retry. `RelayUnavailable` and `RelayRateLimited` are the two that
may succeed later; a `put` is idempotent and safe to repeat.
