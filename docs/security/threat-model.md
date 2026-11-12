---
title: Threat model
description: What the Relay client holds, what could go wrong with it, and where its responsibility ends.
weight: 51
---

# Threat model

This covers the client package only. How Relay authenticates keys, isolates
organizations and scopes, and stores data is the service's own concern.

## What the client holds

- **The API key** (`RELAY_KEY`). Whoever has it can do what its abilities allow, for
  the whole organization if it is an organization key.
- **Nothing else.** The client keeps no state, no cache and no files.

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

## What the client does not do

- **It does not decide who may see which scope.** With an organization key,
  `Relay::forScope($ref)` reads whatever scope `$ref` names. If `$ref` comes from a
  route or a form, your app must check that the current user belongs to that workspace
  first. Relay cannot: it sees only your organization key.
- **It does not verify the url.** `RELAY_URL` is trusted configuration. Pointing it at
  another host sends the key there. Use `https`.
- **It does not validate the key format** and does not know a live key from a test key
  before asking `whoami`.
- **It does not keep the key out of your own logs.** If your app logs outgoing request
  headers (an HTTP client middleware, a debugging tool), the key is in them.
- **It does not sign or verify anything.** There is no webhook signature verification
  here: Relay does not deliver webhooks yet, and receiving them is not this package's
  job.
- **It does not rate limit or retry.** A loop over many scopes can run into Relay's rate
  limit; `RelayRateLimited::$retryAfter` says how long to wait.

## Handling the key

- Keep it in the environment or a secret store, not in the repository.
- Use a scope key where an app only ever works for one scope, and an `rlk_test_` key
  outside production.
- A revoked key answers 401 (`RelayUnauthorized`) on the next call.
