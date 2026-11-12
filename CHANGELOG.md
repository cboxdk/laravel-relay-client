# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.2.0] - 2026-11-12

### Added

- **Click tracking.** `Relay::domains()->trackClicks($id)` and `->tracking($id, new
  TrackingChanges(…))` turn it on or off for a sending domain and name a host of its own
  for the links (`PATCH /v1/domains/{id}`); `SendingDomain::$tracking` reads it back, and
  a record with the purpose `tracking` appears in `$records`. One mail can say otherwise
  with `OutgoingMail::$trackClicks`, or with the header `X-Relay-Track-Clicks` through the
  `relay` mail transport; `Mail::$tracksClicks` is the answer it was accepted with.
- **`mail.clicked`** as `Webhooks\Events\MailClicked` (`id`, `projectId`, `linkId`, `url`,
  `clickedAt`). It names no recipient.
- **`mail.delivered`** as `Webhooks\Events\MailDelivered` (`id`, `projectId`, `recipient`): the
  recipient's server accepted the mail. It says nothing about inbox or junk.
- **One receiving address.** `Relay::inboundRoutes()->registerAddress('support@acme.dk')`
  and `->registerForwarding('billing@acme.dk')`, which gets an address of Relay's that
  yours forwards to. `InboundRouteType::Address`, `InboundRoute::$address` and
  `$forwardFor`.
- **The fake** follows: `seedTracking()`, `assertDomainTracksClicks()`, single and
  forwarding addresses, and `Operation::UpdateDomain`.

### Changed

- **Breaking: a scope is called a project**, as in Relay itself and the other Cbox apps.
  Relay's API is now `/v1/projects` with the header `Relay-Project`, and ids start with
  `prj_`. Every name follows: `Relay::forProject()`, `Relay::projects()`, `putProject()`,
  `Data\Project`, `RelayProjectRequired`, `ProjectOutboundPaused`, the config keys
  `relay.project` and `relay.project_key` (`RELAY_PROJECT`, `RELAY_PROJECT_KEY`), the
  mail header `X-Relay-Project`, and the fake's `seedProject()` and `assertProjectPut()`.
  There are no aliases for the old names. The webhook envelope still carries the owner
  under `scope`: that is the envelope's own field.
- **Breaking for your own `Contracts\RelayTransport`:** the contract gained
  `updateDomain()`, and `createInboundRoute()` takes a nullable `$domain` and two more
  arguments at the end (`$localPart`, `$forwardFor`). Code that calls
  `Relay::inboundRoutes()->register()` is unaffected.
- The fake's verification record for a domain route is now named `_relay-challenge.<domain>`,
  as Relay's is, and a route that became active no longer carries one.

## [0.1.0] - 2026-11-12

The first release.

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
- **Erasure.** `subject` as the last argument of `publish()`, `publishNow()` and
  `NewEvent`, carried by the queued job and checked before anything is queued;
  `Relay::eraseSubject()` removes a subject's events, with `Data\Erasure`.
- **Inbound webhooks.** `Relay::sources()` with `register`, `list`, `find`,
  `rotateSecret`, `pause`, `resume` and `delete`; `Data\Source` and `SourceScheme`.
- **Domain reputation.** `Relay::domains()->reputation()` with `Data\DomainReputation`.
- `Data\AttemptOutcome`, with `PingResult::outcome()` and `succeeded()`. The raw string
  stays, so an outcome Relay adds later does not break a read.
- Relay's 422 with the type `scope_required` is thrown as `RelayScopeRequired`.
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
- **Held mail.** `Relay::releaseMail()` and `Relay::discardMail()` for a mail the
  filter quarantined; a mail that is not held throws `RelayConflict` with the type
  `mail_not_held`.
- `RelayException::type()`: the name of Relay's problem type, to branch on.
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
  `assertNothingPushed`, `assertJudged`, `assertNothingJudged`, `assertInboundErased`,
  `assertInboundRouteRegistered`, `assertSubjectErased`, `assertNoSubjectErased`,
  `assertSourceRegistered`, `assertNoSourceRegistered`, `assertMailReleased` and
  `assertMailDiscarded`, plus seeders and
  `acceptAnyScope()`.
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

[Unreleased]: https://github.com/cboxdk/laravel-relay-client/compare/v0.1.0...main
[0.1.0]: https://github.com/cboxdk/laravel-relay-client/releases/tag/v0.1.0
