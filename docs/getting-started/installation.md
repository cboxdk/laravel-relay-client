---
title: Installation
description: Install the package, set RELAY_URL and RELAY_KEY, and what each config key does.
weight: 11
---

# Installation

```bash
composer require cboxdk/laravel-relay-client
```

The service provider and the `Relay` facade alias are discovered automatically.

## Environment

```dotenv
RELAY_URL=https://api.cboxrelay.com
RELAY_KEY=rlk_live_…
```

Keys start with `rlk_live_` or `rlk_test_`. A test key works on Relay's test
environment; `Relay::whoami()->environment` tells which one a key is on.

With `RELAY_URL` or `RELAY_KEY` empty, every call throws
`Cbox\Relay\Client\Exceptions\RelayNotConfigured` and nothing is sent.

## Config

```bash
php artisan vendor:publish --tag=relay-config
```

| Key | Env | Default | What it is |
|-----|-----|---------|------------|
| `relay.url` | `RELAY_URL` | none | The Relay API. A trailing slash is ignored. |
| `relay.key` | `RELAY_KEY` | none | The API key, sent as `Authorization: Bearer …`. |
| `relay.timeout` | `RELAY_TIMEOUT` | `5` | Seconds a whole call may take. |
| `relay.connect_timeout` | `RELAY_CONNECT_TIMEOUT` | `2` | Seconds connecting may take. |
| `relay.scope` | `RELAY_SCOPE` | none | Optional default scope: every call acts for it. See [Authentication](../core-concepts/authentication.md). |

A timeout that is not a positive number falls back to its default.

## Check it

```php
use Cbox\Relay\Client\Facades\Relay;

Relay::whoami()->organization->name;
```
