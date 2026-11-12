---
title: Commands
description: relay:install, relay:doctor, relay:ping and relay:tail, and the optional health check.
weight: 13
---

# Commands

| Command | What it does |
|---------|--------------|
| `php artisan relay:install` | Publishes `config/relay.php` and lists the environment variables to set. `--store` also publishes the migration of [the local store](../core-concepts/delivery-status.md); `--force` overwrites. |
| `php artisan relay:doctor` | Checks the setup and exits non-zero when something would break. `--no-ping` looks at the configuration only. |
| `php artisan relay:ping {connection?}` | Asks Relay who the key is and times the answer. |
| `php artisan relay:tail` | The newest lines of [the delivery log](../core-concepts/deliveries.md). `--limit=20`, `--channel=`, `--status=`, `--project=`, `--connection=`. |

## What the doctor looks at

For every [connection](../core-concepts/connections.md):

- **Key and URL** are set, and the key's prefix says `live` or `test`.
- **Webhook secret** is set (a warning when it is not).
- **Relay answers** `whoami`, and its environment is the one the key's prefix names.
- **Project**: a default project that is set exists in Relay; a project key is not told
  to act for another project than its own (a failure: Relay refuses that). No project
  set is fine: Relay then uses the key's own or the project `default`.

Then once:

- **Webhook route**: whether the package's route is registered, and a failure when it is
  registered with no secret (every delivery would answer 500).
- **Store**: off, or both tables present.
- **Mailer**: which mailers use the `relay` transport, and whether one is the default.

A failure is something that will not work. A warning is something that may be what you
meant. The doctor never prints a key or a secret.

Run it in a deploy pipeline after the config is in place:

```bash
php artisan relay:doctor || exit 1
```

## Health check

With [cboxdk/laravel-health](https://github.com/cboxdk/laravel-health) installed, add
`Cbox\Relay\Client\Health\RelayConnectionCheck::class` to `health.checks`. It asks Relay
who each connection's key is and reports `critical` when one does not answer. The health
package is a suggestion, not a requirement: without it the class is simply never loaded.

It calls another service. Put it where a monitor reads it, not on a liveness probe that
restarts your app when Relay is slow.
