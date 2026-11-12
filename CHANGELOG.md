# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.2.2] - 2026-11-13

### Fixed

- **Laravel's `failover` mailer now moves on from `relay`.** The `relay` mail transport
  let Relay's own exceptions through, and Symfony's failover only moves on for a
  `TransportExceptionInterface`, so `['relay', 'resend']` never fell back, not even with
  Relay down or unconfigured. The transport now throws a Symfony `TransportException`,
  with the Relay exception as its previous, when Relay cannot take the mail right now or
  not with this key: `RelayNotConfigured`, `RelayUnavailable` (unreachable, a timeout, a
  5xx), `RelayRateLimited` (429) and `RelayUnauthorized`/`RelayForbidden` (401, 403).
  When Relay refuses the mail itself, `RelayInvalid` (422) and `RelayTooLarge` (413) are
  still thrown as they are, so the failover stops and the send fails: the next mailer
  would send the same bad mail.

  Code that sends through the `relay` mailer and catches one of those five Relay
  exceptions directly should catch `TransportException` and read `getPrevious()`.
  `Relay::sendMail()` itself is unchanged and still throws the Relay exceptions.

### Documentation

- The mail docs say that `X-Relay-Idempotency-Key` must be stable per mail (derived from
  what the mail is about, not random per attempt) for a retry to go out once, and that a
  failover after a lost answer can send a mail through both mailers.

## [0.2.1] - 2026-11-12

Everything here is additive: code written against 0.2.0 keeps working.

### Added

- **Sending limits.** `Relay::sendingLimits()`: `current()` reads the project's limits per
  hour and per day, what it has used, its standing and the last moves; `request($hour,
  $day, $reason)` asks for more and comes back `approved` or `pending`; `requests()` and
  `findRequest($id)` read the answers. The key needs the ability `limits:request`. The
  calls are on a new contract, `Contracts\SendingLimitsTransport`, beside
  `RelayTransport`: a transport of your own written against 0.2.0 still satisfies
  `RelayTransport`, and `sendingLimits()` says so plainly if it does not make these calls.
- **A refusal for a sending limit says so.** `RelayRateLimited::sendingLimited()` tells a
  sending limit (`sending_limited`) from the key's own rate limit, and `scope()` says
  whose it was: `project` or `organization`. `retryAfter` is how long to wait.
- **Who holds a mail.** `Mail::$heldBy` (`project`, `relay` or null) and
  `Mail::heldByRelay()`. Releasing a mail Relay's own guard holds throws `RelayForbidden`,
  and `RelayForbidden::heldByRelay()` says that is why.
- **Finding a mail again.** `Mail::$idempotencyKey`, `MailFilter::idempotencyKey()` and
  `MailFilter::metadata($key, $value)`. On `409` `idempotency_key_reused`,
  `RelayConflict::mailId()` is the mail the first call accepted.
- **DNS status per record.** `DnsRecord::$status` (`found`, `missing`, `different`,
  `unchecked`) and `DnsRecord::found()` on a sending domain's records.
- **A problem's own members.** `Problem::$extensions` and `Problem::extension($name)`.
- **Events.** `MailAccepted` and `MailBlocked` carry `state` and `heldBy`
  (`MailBlocked::held()`, `::heldByRelay()`); `ProjectOutboundPaused` carries `cause`. New
  typed events `MailReleased`, `MailDiscarded`, `ProjectOutboundLimited`,
  `ProjectOutboundWatched`, `ProjectOutboundLimitsChanged`, `ProjectLimitRequested` and
  `ProjectLimitRequestDecided`, each with its `Webhooks\Received\*` Laravel event. Until
  now these deliveries arrived as `OtherEventReceived`; a listener on that for one of
  these types should listen for the typed event instead.
- **The local store** makes a mail `quarantined` on a `mail.blocked` that says it is only
  held, `queued` or `scheduled` again on `mail.released`, and `blocked` on `mail.discarded`.
- **The fake:** `limitSending()`, `liftSendingLimit()` and `seedSendingLimits()` (real
  counting of recipients per hour and day), sending-limit requests with
  `decideSendingLimitRequest()` and `grantLimitRequestsAtOnce()`,
  `assertSendingLimitRequested()` and `assertNoSendingLimitRequested()`,
  `holdMailByRelay()`, the idempotency key kept on a mail and named on a conflict, and a
  status on each DNS record.
- Docs: a new page, Sending limits and holds.

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
- **Tags and metadata on a mail.** `OutgoingMail::$tags` and `$metadata` (`tagged()`,
  `withMetadata()`), read back as `Mail::$tags` and `$metadata`, and carried by every
  event about a sent mail: `MailAccepted`, `MailBlocked`, `MailDelivered`, `MailDeferred`,
  `MailBounced`, `MailComplained`, `MailClicked` and `MailOpened` each have `$tags` and
  `$metadata`. Through the mail transport a mailable's own `tags:` and `metadata:` (Symfony's
  `TagHeader` and `MetadataHeader`) become the mail's.
- **Inline images.** `MailAttachment::$contentId`. Through the mail transport an embedded
  image (`$message->embed()`, `embedData()`) goes out inline and the `cid:` in the HTML
  follows the id Relay gets; before, it went as an ordinary attachment.
- **Send later.** `OutgoingMail::$sendAt` (`sendingAt()`), the statuses `scheduled` and
  `cancelled`, `Mail::$sendAt` and `scheduled()`, and `Relay::cancelMail($id)`
  (`POST /v1/mail/{id}/cancel`; `RelayConflict` with the type `mail_not_scheduled` when it
  no longer waits). Through the mail transport: the header `X-Relay-Send-At`.
  `Mail::accepted()` is also true for a scheduled mail.
- **Many mails in one call.** `Relay::sendMailBatch($mails, $idempotencyKey)` (`POST
  /v1/mail/batch`) answers one `Data\MailBatchResult` per mail, in order: the status, and
  the `Mail` or the `Problem`. A refused mail does not throw and does not stop the others.
- **A list.** `Relay::mails(?MailFilter $filter, $limit, $cursor)` (`GET /v1/mail`), with
  `Data\MailFilter` for status, sender, recipient, tag and time.
- **Open tracking.** `Relay::domains()->trackOpens($id)`, `TrackingChanges::$opens`,
  `DomainTracking::$opens`, `OutgoingMail::$trackOpens` (`trackingOpens()`),
  `Mail::$tracksOpens`, and the header `X-Relay-Track-Opens` through the mail transport.
  Off unless turned on. An open is a weak signal and personal data; the docs say why.
- **`mail.opened`** as `Webhooks\Events\MailOpened` (`id`, `projectId`, `openedAt`) and
  **`mail.deferred`** as `Webhooks\Events\MailDeferred` (`id`, `projectId`, `recipient`,
  `status`, `diagnostic`), with their Laravel events `Webhooks\Received\MailOpened` and
  `MailDeferred`. The local store keeps both and counts opens in `relay_messages.opened`.
- **The fake** follows these too: scheduled and cancelled mail (`assertMailCancelled()`),
  batches, `mails()`, tags and metadata, open tracking (`assertDomainTracksOpens()`,
  `seedTracking(opens:)`), and `Operation::ListMail`, `SendMailBatch` and `CancelMail`.

- **A webhook route with Laravel events.** `Relay::webhookRoutes()` in a routes file, or
  `relay.webhook.route`, registers `POST relay/webhook` (and `relay/webhook/{connection}`).
  Off by default. A verified delivery is handled once (its delivery id is remembered in
  the cache) and dispatched as one Laravel event per type: `Webhooks\Received\MailDelivered`,
  `MailBounced`, `MailClicked` and the rest, each carrying the typed data and the
  delivery, and `Received\OtherEventReceived` for every other type. All implement
  `Contracts\ReceivedWebhook`. The answer is `204` after the synchronous listeners
  returned; a listener that throws answers `500` and the retry is handled as new.
- **Local delivery status, optional.** With `relay.store.enabled` and the migration
  from `relay:install --store`, every mail sent through `Relay::sendMail()` or the mail
  transport is a `Models\RelayMessage` row and every webhook event about a mail a
  `Models\RelayMessageEvent` row that brings it up to date. Idempotent; off by default,
  and off needs no database. The subject is kept as a hash unless `relay.store.subject`
  says `plain` or `none`. `Contracts\MessageStore` is the seam, `Testing\FakeMessageStore`
  the in-memory one.
- **Commands:** `relay:install`, `relay:doctor` (exits non-zero when something would
  break; never prints a key or a secret), `relay:ping` and `relay:tail`.
- **A health check** for cboxdk/laravel-health: `Health\RelayConnectionCheck`. The health
  package is a suggestion, not a requirement.
- **Several connections by name.** `relay.connections`, `Relay::connection('billing')`,
  and `'connection' => 'billing'` on a mailer. Each has its own key, project and webhook
  secret; the top level of the config is the connection `default`, so an existing config
  keeps working. A published event is sent with the key of the connection it was
  published on. `relay.transport.{name}` binds a transport for one connection.

### Changed

- **Two more `illuminate/*` components are required:** `illuminate/console` and
  `illuminate/database`. A Laravel app has both.
- **`Relay`'s constructor, `HttpRelayTransport`'s, `RelayMailTransport`'s,
  `QueuedEventPublisher`'s and `PublishEvent`'s took optional trailing arguments** for the
  connection (and, on `Relay`, the store). `PublishEvent::handle()` takes the client as an
  optional second argument. Nothing changes for code that does not pass them.
- **Breaking: a project no longer has to be named.** Relay now acts for the key's own
  project or, for an organization key, for the environment's project `default` (made on
  first use) when a call sends no `Relay-Project`, and it no longer answers
  `project_required`. So the client stopped refusing: a call with no project named goes
  out without the header. Removed with it: the exception `RelayScopeRequired` (it is not
  renamed, it is gone), the config key `relay.scope_key` / `RELAY_SCOPE_KEY` (a project
  key needs no announcing any more), the `$projectKey` argument of `Relay`'s constructor
  and `ConnectionConfig::$projectKey`, and `ActingProject::required()` and
  `$lockedByKey`. If you keep a project per customer, name it on every call: one that
  forgets now lands in `default` instead of throwing. The fake does the same, under
  `FakeRelay::DEFAULT_PROJECT`. `relay:doctor` no longer warns when no project is set, and
  fails when a project key is told to act for another project than its own.
- **Breaking: a scope is called a project**, as in Relay itself and the other Cbox apps.
  Relay's API is now `/v1/projects` with the header `Relay-Project`, and ids start with
  `prj_`. Every name follows: `Relay::forProject()`, `Relay::projects()`, `putProject()`,
  `Data\Project`, `ProjectOutboundPaused`, the config key `relay.project`
  (`RELAY_PROJECT`), the
  mail header `X-Relay-Project`, and the fake's `seedProject()` and `assertProjectPut()`.
  There are no aliases for the old names. The webhook envelope still carries the owner
  under `scope`: that is the envelope's own field.
- **Breaking for your own `Contracts\RelayTransport`:** the contract gained
  `updateDomain()`, `listMail()`, `sendMailBatch()` and `cancelMail()`, and
  `createInboundRoute()` takes a nullable `$domain` and two more arguments at the end
  (`$localPart`, `$forwardFor`). Code that calls `Relay::inboundRoutes()->register()` is
  unaffected.
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

[Unreleased]: https://github.com/cboxdk/laravel-relay-client/compare/v0.2.2...main
[0.2.2]: https://github.com/cboxdk/laravel-relay-client/releases/tag/v0.2.2
[0.2.1]: https://github.com/cboxdk/laravel-relay-client/releases/tag/v0.2.1
[0.2.0]: https://github.com/cboxdk/laravel-relay-client/releases/tag/v0.2.0
[0.1.0]: https://github.com/cboxdk/laravel-relay-client/releases/tag/v0.1.0
