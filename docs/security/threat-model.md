---
title: Threat model
description: What the Relay client holds, what could go wrong with it, and where its responsibility ends.
weight: 51
---

# Threat model

This covers the client package only. How Relay authenticates keys, isolates
organizations and projects, and stores data is the service's own concern.

## What the client holds

- **The API key** (`RELAY_KEY`). Whoever has it can do what its abilities allow, for
  the whole organization if it is an organization key.
- **The webhook secret** (`RELAY_WEBHOOK_SECRET`, and the previous one during a
  rotation). Whoever has it can forge a webhook your app will accept as Relay's.
- **Queued publish jobs.** A `PublishEvent` job holds the event's type, payload,
  idempotency key and project, in whatever your queue stores jobs in, until it has run.
  It never holds the API key. A payload is as protected as your queue is.
- **Nothing else.** The client keeps no cache and no files.

## What the client does

- **The key goes in the `Authorization` header and nowhere else.** It is not put in a
  URL, a log line or an exception message. Exception messages are built from the status,
  title and detail Relay sent; connection errors carry the HTTP client's message, which
  names the host and the cause.
- **Path segments are encoded.** An external ref is `rawurlencode`d, so a ref containing
  `/` or `?` cannot reach a different endpoint.
- **Nothing is sent when unconfigured.** An empty url or key throws before any request.
- **Bodies are checked.** A success answer with missing or mistyped fields throws
  `RelayMalformedResponse` instead of yielding a half-filled object.
- **TLS is the HTTP client's default.** The package does not disable certificate
  verification and offers no option to.
- **A call that names no project never guesses one.** It goes out without a
  `Relay-Project` header, and Relay decides: the project the key is locked to or, for an
  organization key, the environment's `default` project. If each customer of yours has a
  project of their own, name it on every call (`forProject()`): a call that forgets to
  lands in `default`, not in a customer's project.
- **Incoming webhooks are verified before they are parsed.** The `relay.webhook`
  middleware checks an HMAC-SHA256 over the raw body with `hash_hmac()` and compares
  with `hash_equals()`, tries every configured secret whichever matches, accepts only a
  timestamp within the tolerance on either side, and reads only the signed timestamp.
  With no secret configured it refuses everything (500): an unset variable does not
  open the route.
- **The mail transport drops what Relay does not let a sender set.** Headers outside the
  allowed set never leave your app, and the three control headers (`X-Relay-Project`,
  `X-Relay-Idempotency-Key`, `X-Relay-Track-Clicks`) are never passed on.

- **The package's webhook route handles a delivery once.** It runs only the verifying
  middleware (no session, no CSRF exemption to maintain), remembers the delivery id in
  the cache, and a named connection's deliveries are verified with that connection's
  secrets alone. A connection name that does not exist has no secrets and is refused.
- **The local store is off by default and keeps a subject as a hash unless told
  otherwise.** When on, it holds recipients' addresses (on event rows) and whatever
  Relay's events carry, in your database, with no retention of its own: that data is
  yours to protect and to delete.
- **`relay:doctor` and `relay:ping` never print a key or a secret.**

## What the client does not do

- **It does not decide who may see which project.** With an organization key,
  `Relay::forProject($ref)` reads, publishes and sends for whatever project `$ref` names.
  The same goes for the `X-Relay-Project` header on a mail and `WebPushMessage::$project`. If `$ref` comes from a
  route or a form, your app must check that the current user belongs to that workspace
  first. Relay cannot: it sees only your organization key.
- **It does not verify the url.** `RELAY_URL` is trusted configuration. Pointing it at
  another host sends the key there. Use `https`.
- **It does not validate the key format** and does not know a live key from a test key
  before asking `whoami`.
- **It does not keep the key out of your own logs.** If your app logs outgoing request
  headers (an HTTP client middleware, a debugging tool), the key is in them.
- **It does not stop a replay inside the tolerance window.** A captured webhook can be
  posted again for as long as its timestamp is fresh (five minutes by default). The
  middleware keeps no record of what it has seen: deduplicate on
  `IncomingWebhook::$deliveryId`.
- **It verifies the `cbox` signature scheme only.** An endpoint registered with
  `standard-webhooks` is signed differently and is refused by the middleware.
- **Its signature check has not met the live service.** It is tested against a
  signature made by the implementation Relay signs with, not against a real delivery.
- **It does not sanitise received mail.** `InboundMessage::$html`, `$text`, `$subject`
  and the headers are whatever a stranger sent. Treat them as hostile input.
- **It does not vet attachment links.** `InboundAttachment::$url` is a short-lived link
  Relay made; whoever has it can download the file until it runs out. Do not log or
  store it.
- **It does not keep the endpoint secret.** `Endpoint::$secret` is returned once, in
  plain text, to your code. Showing, storing and protecting it is yours.
- **It does not check a mail's sender or recipients.** The `relay` mail transport sends
  what your app's mailer was given; Relay decides whether the sender's domain may send.
- **It does not rate limit, and retries only the queued publish.** A loop over many
  projects can run into Relay's rate limit; `RelayRateLimited::$retryAfter` says how long
  to wait.

## Handling the key

- Keep it, and the webhook secret, in the environment or a secret store, not in the
  repository.
- Use a project key where an app only ever works for one project, and an `rlk_test_` key
  outside production.
- A revoked key answers 401 (`RelayUnauthorized`) on the next call.
