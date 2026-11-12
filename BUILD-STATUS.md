# Build status

What this package does today, and what it does not. It follows Cbox Relay's own
milestones: this is the client for M0 of the service (authentication, scopes, an empty
delivery log). Nothing has been released or tagged.

## Built and tested

- `Contracts\RelayTransport`: one method per endpoint. `Http\HttpRelayTransport`
  implements it over Laravel's HTTP client with the bearer key, `Relay-Scope` when
  acting for a scope, `Idempotency-Key` on a put when given, and the configured
  `timeout` / `connect_timeout`.
  - `GET /v1/whoami`
  - `PUT /v1/scopes/{external_ref}` (201 → `Scope::$created` true, 200 → false)
  - `GET /v1/scopes` (limit, cursor)
  - `GET /v1/scopes/{ref}` (404 → `null`)
  - `GET /v1/deliveries` (channel, status, from, to, limit, cursor)
- Error mapping from `application/problem+json`: 401 `RelayUnauthorized`, 403
  `RelayForbidden`, 404 `RelayNotFound`, 409 `RelayConflict`, 422 `RelayInvalid` (field
  errors), 429 `RelayRateLimited` (`Retry-After` in seconds or as an HTTP date), 5xx and
  connection errors `RelayUnavailable`, any other status the base `RelayException`.
  Plus `RelayNotConfigured` (no url or key) and `RelayMalformedResponse` (a 2xx body the
  client cannot read).
- Value objects: `Identity` (`Organization`, `Environment`, `ApiKey`, `ScopeReference`),
  `Scope`, `Delivery`, `Page<T>`, `DeliveryFilter`, `Problem`.
- The `Relay` manager, the `Scopes` front and the `Relay` facade, with `forScope()`,
  `withoutScope()` and the optional `relay.scope` default.
- `Testing\FakeRelay` (scopes stored, put idempotent, deliveries seeded, filtered and
  paged, every call recorded, assertions), `Relay::fake()`, `InteractsWithRelay`. The
  package's own suite uses the fake everywhere except the HTTP transport tests, which
  use `Http::fake()`.

## Not built

- **Publishing events** (webhooks out through Relay). Comes with M1 of the service.
- **A mail driver, mail or push sending.** Comes with M6 of the service.
- **Retries.** One call is one request. `RelayUnavailable` and `RelayRateLimited` tell
  the caller it may try again; the client does not.
- **Iterating all pages.** `Page::$nextCursor` is handed back by the caller; there is no
  lazy "all scopes" or "all deliveries" helper.
- **Suspending, deleting or renaming scopes** beyond what a `put` does: the M0 API has
  no such endpoints.
- **Reading one delivery by id, delivery attempts or payloads**: not in the M0 API.
- **Key management** (creating, rotating, revoking keys): done in Relay itself.
- **Client-side key checks.** The client does not validate the `rlk_…` format and does
  not know whether a key is an organization key or a scope key; a scope key that sends
  `Relay-Scope` is refused by Relay, not by this package.

## Not verified

- **Against a running Relay.** The service is being built in parallel. Every HTTP test
  runs against `Http::fake()` with bodies written from the v1 API description, so the
  shapes are as described, not as observed. The first contract test against the real
  service decides whether they agree.
- **`Delivery` nullability.** `scope_id`, `response_code`, `error` and `duration_ms` are
  read as nullable, everything else as required. `channel` and `status` stay strings:
  the description names `webhook` and no status values.
- **The fake's judgement calls** where the description is silent; they are listed in
  [docs/getting-started/testing.md](docs/getting-started/testing.md#where-the-fake-decides).
- **CI.** The workflow is written (PHP 8.4/8.5 × Laravel 12/13) and has never run:
  nothing is pushed. Locally the gate ran on PHP 8.5 with Laravel 13, and the suite also
  on Laravel 12.
