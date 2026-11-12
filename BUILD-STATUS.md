# Build status

What this package does today, and what it does not. It is the client for Cbox Relay's
v1 API as described in the service's `docs/openapi/relay.v1.yaml` and its API guides.
Nothing has been released or tagged.

## Built and tested

- `Contracts\RelayTransport`: one method per endpoint. `Http\HttpRelayTransport`
  implements it over Laravel's HTTP client with the bearer key, `Relay-Scope` when
  acting for a scope, `Idempotency-Key` where the API takes one and the caller gave it,
  and the configured `timeout` / `connect_timeout`.
  - `GET /v1/whoami`
  - `GET /v1/scopes`, `GET|PUT /v1/scopes/{ref}`
  - `GET /v1/deliveries`
  - `GET|PUT /v1/event-types`
  - `GET|POST /v1/events`, `POST /v1/events/batch`, `GET /v1/events/{id}`
  - `POST /v1/webhook-deliveries/{id}/retry`
  - `POST /v1/erasures`
  - `GET|POST /v1/sources`, `GET|DELETE /v1/sources/{id}`, `PUT /v1/sources/{id}/secret`,
    and `pause`, `resume`
  - `GET|POST /v1/endpoints`, `GET|PATCH|DELETE /v1/endpoints/{id}`, and `pause`,
    `resume`, `rotate-secret`, `ping`, `replay`
  - `POST /v1/judge`, `GET /v1/judgments/{id}`
  - `GET|POST /v1/inbound/routes`, `DELETE /v1/inbound/routes/{id}`, `…/verify`
  - `GET /v1/inbound`, `GET|DELETE /v1/inbound/{id}`
  - `GET /v1/push/key`, `GET|POST /v1/push/subscriptions`,
    `DELETE /v1/push/subscriptions/{id}`, `POST /v1/push`, `GET /v1/push/{id}`
  - `GET|POST /v1/domains`, `GET|DELETE /v1/domains/{id}`, `…/verify`, `…/reputation`
  - `GET|POST /v1/suppressions`, `DELETE /v1/suppressions/{address}`
  - `POST /v1/mail`, `GET /v1/mail/{id}`, `POST /v1/mail/{id}/release`, `…/discard`
- Error mapping from `application/problem+json`: 401 `RelayUnauthorized`, 403
  `RelayForbidden`, 404 `RelayNotFound`, 409 `RelayConflict`, 413 `RelayTooLarge`, 422
  `RelayInvalid` (field errors), 429 `RelayRateLimited` (`Retry-After` in seconds or as
  an HTTP date), 5xx and connection errors `RelayUnavailable`, any other status the base
  `RelayException`. Plus `RelayNotConfigured`, `RelayMalformedResponse`,
  `RelayScopeRequired` and `RelayWebhookRejected`.
- The `Relay` manager, its fronts (`Scopes`, `EventTypes`, `Endpoints`, `Events`,
  `Sources`, `InboundRoutes`, `Inbound`, `PushSubscriptions`, `Domains`, `Suppressions`)
  and the
  facade, with `forScope()`, `withoutScope()`, `relay.scope` and `relay.scope_key`.
  Calls that need a scope throw `RelayScopeRequired` before a request is made, and
  Relay's own 422 `scope_required` is thrown as the same exception.
- `Relay::publish()` through `Contracts\EventPublisher`: `Jobs\PublishEvent` queued after
  commit with a generated idempotency key, its retry rules (permanent on 403, 404, 413,
  422; `Retry-After` on 429; backoff otherwise), `relay.publish.sync`, `publishNow()` and
  `publishBatch()`. An event's `subject` travels with all of them, and
  `eraseSubject()` removes a subject's events.
- The `relay` mail transport (`Mail\RelayMailTransport`, `Mail\EmailMapper`): from, to,
  cc, bcc, reply-to, subject, html, text, allowed headers, attachments as base64; scope
  and idempotency key from control headers.
- `Notifications\RelayWebPushChannel` and `WebPushMessage`.
- The `relay.webhook` middleware, `Webhooks\WebhookSignature` (the `cbox` scheme,
  tolerance, several secrets), `Webhooks\IncomingWebhook` (the `cbox` and CloudEvents
  envelopes) and typed objects for Relay's nine own events.
- `Testing\FakeRelay` for all of it, `Relay::fake()`, `InteractsWithRelay`. The
  package's own suite uses the fake everywhere except the HTTP transport tests and the
  publish job's retry rules, which use `Http::fake()`.
- The gate (`composer qa`): Pint, PHPStan level max without ignores or a baseline, Pest,
  the license check, `composer audit --no-dev` and the SBOM check. The suite was run on
  PHP 8.5 with Laravel 13 and with Laravel 12.

## Not built

- **Iterating all pages.** `Page::$nextCursor` is handed back by the caller; there is no
  lazy "all events" helper.
- **Retries, except for the publish job.** One call is one request.
- **A queued `publishBatch()`, mail or push.** `publish()` is the only call that queues
  itself. Queue a mailable or a notification the Laravel way.
- **Verifying `standard-webhooks` signatures.** The middleware knows the `cbox` scheme.
- **Replay protection for incoming webhooks.** No record of seen deliveries is kept;
  deduplicate on `IncomingWebhook::$deliveryId`.
- **Batched webhook deliveries** (the engine's batch envelope): the API has no way to
  ask for them, so they are not parsed.
- **Inline images in mail.** The API's attachments have no content id, so an embedded
  image goes as an ordinary attachment.
- **Suspending, deleting or renaming scopes** beyond what a `put` does, and **key
  management**: not in the API.
- **Client-side key checks.** The client does not validate the `rlk_…` format and does
  not know an organization key from a scope key; `relay.scope_key` is how you tell it.

## Not verified

- **Against a running Relay.** Every HTTP test runs against `Http::fake()` with bodies
  written from the API description, so the shapes are as described, not as observed.
  The first contract test against the real service decides whether they agree.
- **The service behind the API.** Relay's API guides say its outbound mail servers and
  its receiving servers are not running yet: no mail leaves Relay and none arrives.
  This package cannot change or observe that. See Relay's own status.
- **The webhook signature against a live delivery.** It is tested against a vector made
  with `cboxdk/laravel-webhook-signature` (the implementation Relay signs with), plus
  the refusals. No delivery from the service has been verified with it.
- **The shapes of Relay's own events against a live delivery.** They follow the field
  lists in Relay's webhook guide, which says it was written from a captured delivery;
  this package has parsed none itself.
- **A typed object for what a source forwards** (`inbound.<name>`): not built. It
  arrives in `IncomingWebhook::$data` (`source`, `provider_event_id`, `content_type`,
  `body`).
- **The mail transport against a real mailer round trip.** The mapping is tested on
  Symfony `Email` objects and through Laravel's mailer onto the fake and `Http::fake()`.
- **The queued publish on a real queue.** The job's rules are tested with Laravel's fake
  queue interactions, and its dispatch with `Bus::fake()`; it has not run on a worker
  against the service.
- **Where the API description is silent**, the client made a choice. The ones that
  matter:
  - `publish` sends its idempotency key in the body (`idempotency_key`), not as the
    `Idempotency-Key` header: the body field is the one the description ties to
    `duplicate`.
  - An event published without a time is stamped by the client when `publish()` is
    called; Relay would use the time it received it.
  - A `subject` is checked client-side as visible ASCII without spaces, 1 to 191. The
    API description states only the maximum length.
  - Statuses, kinds, envelopes and outcomes in answers are kept as strings.
- **The fake's judgement calls**; they are listed in
  [docs/getting-started/testing.md](docs/getting-started/testing.md#where-the-fake-decides).
- **CI.** The workflow (PHP 8.4/8.5 × Laravel 12/13) is in the repository; its result
  on GitHub is the record, not this file.
