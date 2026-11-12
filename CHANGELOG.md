# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

Nothing has been released yet. Everything below is on `main`.

### Added

- **Webhooks out.** `Relay::eventTypes()->declare()/all()`; `Relay::endpoints()` with
  `register`, `list`, `find`, `update`, `delete`, `pause`, `resume`, `rotateSecret`,
  `ping` and `replay`; `Relay::events()` with `find`, `list` and `retryDelivery`.
- **Publishing.** `Relay::publish()` dispatches the queued `Jobs\PublishEvent` after
  commit, on a configurable connection and queue, with an idempotency key generated at
  dispatch when none is given. The job fails without retrying on 403, 404, 413 and 422,
  honours `Retry-After` on 429 and retries everything else with a backoff.
  `Relay::publishNow()` calls inline and returns the result; `Relay::publishBatch()`
  sends up to 100 events in one call. `relay.publish.sync` makes `publish()` inline.
  `Contracts\EventPublisher` is the seam.
- **The judge.** `Relay::judge()` and `Relay::judgment()` with a typed `Judgment`
  (verdict scores, category, findings).
- **Inbound mail.** `Relay::inboundRoutes()` (`register`, `list`, `verify`, `remove`)
  and `Relay::inbound()` (`list`, `find`, `erase`).
- **Web Push.** `Relay::pushKey()`, `Relay::pushSubscriptions()` (`subscribe`, `list`,
  `unsubscribe`), `Relay::push()`, `Relay::pushMessage()`, and the
  `Notifications\RelayWebPushChannel` notification channel with `WebPushMessage`.
- **Outbound mail.** `Relay::domains()` (`register`, `list`, `find`, `verify`,
  `remove`), `Relay::suppressions()` (`add`, `list`, `remove`), `Relay::sendMail()`,
  `Relay::mail()`, and a `relay` mail transport for Laravel's mailer that maps a
  Symfony `Email` to the API's structured body and drops headers Relay does not allow.
- **Webhooks from Relay.** The `relay.webhook` route middleware (the `cbox` signature
  scheme, a tolerance window, two secrets during a rotation), `Webhooks\WebhookSignature`,
  `Webhooks\IncomingWebhook` for the `cbox` and CloudEvents envelopes, and typed objects
  for `mail.received`, `mail.accepted`, `mail.blocked`, `mail.bounced`,
  `mail.complained`, `push.delivered`, `push.expired`, `endpoint.disabled` and
  `scope.outbound_paused`. `Contracts\WebhookSecrets` picks the secrets per request.
- **Scope handling.** A call that needs a scope throws `RelayScopeRequired` before
  anything is sent when none is set. `relay.scope_key` for scope keys.
- `RelayTooLarge` for 413.
- `FakeRelay` covers all of the above in memory, with `assertPublished`,
  `assertPublishedTimes`, `assertNotPublished`, `assertNothingPublished`,
  `assertEventTypesDeclared`, `assertEndpointRegistered`, `assertMailSent`,
  `assertMailSentTimes`, `assertNoMailSent`, `assertPushed`, `assertPushedTimes`,
  `assertNothingPushed`, `assertJudged`, `assertNothingJudged`, `assertInboundErased`
  and `assertInboundRouteRegistered`, plus seeders and `acceptAnyScope()`.
  `Relay::fake()` makes `publish()` record at once instead of queueing.
- `Cbox\Relay\Client\Contracts\RelayTransport` with `Http\HttpRelayTransport` over
  Laravel's HTTP client: `GET /v1/whoami`, `PUT /v1/scopes/{external_ref}`,
  `GET /v1/scopes`, `GET /v1/scopes/{ref}`, `GET /v1/deliveries`.
- The `Relay` manager and the `Relay` facade: `whoami()`, `scopes()->put()/find()/list()`,
  `deliveries()`, `forScope()`, `withoutScope()`.
- Typed value objects (`Identity`, `Scope`, `Delivery`, `Page`, `DeliveryFilter`,
  `Problem`) and typed exceptions for Relay's RFC 9457 problem answers.
- `Cbox\Relay\Client\Testing\FakeRelay`, `Relay::fake()` and `InteractsWithRelay`.

### Changed

- `Scope::$name` and `ScopeReference::$name` are nullable, as the API describes them.
- A `find…` that gets a 404 because the *scope* does not exist (`scope_not_found`) now
  throws `RelayNotFound` instead of answering `null`. `findScope()` is unchanged.
- The fake's default key has every ability, and its problems carry Relay's `type` URLs.
- The package now requires `illuminate/bus`, `illuminate/queue`, `illuminate/mail`,
  `illuminate/notifications`, `illuminate/routing`, `symfony/mailer` and `symfony/mime`
  besides `illuminate/contracts`, `illuminate/http` and `illuminate/support`.

[Unreleased]: https://github.com/cboxdk/laravel-relay-client/commits/main
