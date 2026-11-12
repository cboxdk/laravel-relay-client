---
title: Configuration reference
description: Every key of config/relay.php, the environment variable behind it, its default and what it does.
weight: 46
---

# Configuration reference

```bash
php artisan vendor:publish --tag=relay-config   # config/relay.php
```

## Connection

| Key | Env | Default | What it is |
|-----|-----|---------|------------|
| `relay.url` | `RELAY_URL` | none | The Relay API. A trailing slash is ignored. |
| `relay.key` | `RELAY_KEY` | none | The API key, sent as `Authorization: Bearer …`. |
| `relay.timeout` | `RELAY_TIMEOUT` | `5` | Seconds a whole call may take. |
| `relay.connect_timeout` | `RELAY_CONNECT_TIMEOUT` | `2` | Seconds connecting may take. |

With `url` or `key` empty every call throws `RelayNotConfigured` and nothing is sent.
A timeout that is not a positive number falls back to its default.

## Project

| Key | Env | Default | What it is |
|-----|-----|---------|------------|
| `relay.project` | `RELAY_PROJECT` | none | The project every call acts for unless `forProject()` says otherwise. Empty: no `Relay-Project` header is sent, and Relay acts for the key's own project or, for an organization key, for the project `default`. |

See [Authentication](../core-concepts/authentication.md#when-no-project-is-named).

## Publishing

| Key | Env | Default | What it is |
|-----|-----|---------|------------|
| `relay.publish.sync` | `RELAY_PUBLISH_SYNC` | `false` | `true` makes `Relay::publish()` call Relay at once instead of queueing a job. |
| `relay.publish.connection` | `RELAY_PUBLISH_CONNECTION` | app default | The queue connection of the publish job. |
| `relay.publish.queue` | `RELAY_PUBLISH_QUEUE` | connection default | The queue of the publish job. |
| `relay.publish.tries` | `RELAY_PUBLISH_TRIES` | `5` | How often the job is attempted in all. |
| `relay.publish.backoff` | none | `[10, 30, 120, 600]` | Seconds before the second attempt, the third and so on. The last value repeats. A comma-separated string works too. |

A `tries` below 1 or a `backoff` without a usable number falls back to the default. See
[Publishing events](../core-concepts/publishing-events.md).

## Webhooks from Relay

| Key | Env | Default | What it is |
|-----|-----|---------|------------|
| `relay.webhook.secret` | `RELAY_WEBHOOK_SECRET` | none | The signing secret of your endpoint. Without one every delivery is refused with 500. |
| `relay.webhook.previous_secret` | `RELAY_WEBHOOK_PREVIOUS_SECRET` | none | The old secret, accepted too while a rotation is under way. |
| `relay.webhook.tolerance` | `RELAY_WEBHOOK_TOLERANCE` | `300` | Seconds a delivery's timestamp may be off, either way. |
| `relay.webhook.route` | `RELAY_WEBHOOK_ROUTE` | none | A path such as `relay/webhook`: registers the package's own endpoint there (and `{path}/{connection}`). Empty registers nothing; `Relay::webhookRoutes()` in a routes file does the same. |
| `relay.webhook.cache` | `RELAY_WEBHOOK_CACHE` | default store | The cache store handled deliveries are remembered in. |
| `relay.webhook.dedupe` | `RELAY_WEBHOOK_DEDUPE` | `172800` | Seconds a handled delivery is remembered, so a retry of it is not handled twice. |

See [Webhooks from Relay](../core-concepts/incoming-webhooks.md).

## Local delivery status

| Key | Env | Default | What it is |
|-----|-----|---------|------------|
| `relay.store.enabled` | `RELAY_STORE` | `false` | `true` keeps a row per sent mail and per webhook event about it. Off needs no database. |
| `relay.store.connection` | `RELAY_STORE_CONNECTION` | app default | The database connection the two tables are on. |
| `relay.store.subject` | `RELAY_STORE_SUBJECT` | `hash` | How a subject is kept: `hash` (SHA-256), `plain` or `none`. Anything else is `hash`. |

See [Local delivery status](../core-concepts/delivery-status.md).

## More connections

`relay.connections` is a map of name to `key`, `project`, `url`,
`timeout`, `connect_timeout` and `webhook.secret` / `webhook.previous_secret`. Everything
above is the connection named `default`. See [Connections](../core-concepts/connections.md).

## The mailer

The `relay` mail transport is configured in `config/mail.php`, not here:

| Key | What it is |
|-----|------------|
| `mail.mailers.<name>.transport` | `relay` |
| `mail.mailers.<name>.project` | Optional: the project this mailer sends for, ahead of `relay.project`. |
| `mail.mailers.<name>.connection` | Optional: the [connection](../core-concepts/connections.md) this mailer sends through. |

See [Outbound mail](../core-concepts/outbound-mail.md#the-mail-transport).
