---
title: Installation
description: Install the package, set RELAY_URL and RELAY_KEY, run a queue worker, and choose how the project is named.
weight: 11
---

# Installation

```bash
composer require cboxdk/laravel-relay-client
```

The service provider and the `Relay` facade alias are discovered automatically.

```bash
php artisan relay:install   # publishes config/relay.php and lists what to set
php artisan relay:doctor    # checks the setup once the keys are in place
```

See [Commands](commands.md).

## Environment

```dotenv
RELAY_URL=https://api.cboxrelay.com
RELAY_KEY=rlk_live_…
```

Keys start with `rlk_live_` or `rlk_test_`. A test key works on Relay's test
environment; `Relay::whoami()->environment` tells which one a key is on.

With `RELAY_URL` or `RELAY_KEY` empty, every call throws
`Cbox\Relay\Client\Exceptions\RelayNotConfigured` and nothing is sent.

## A queue worker

`Relay::publish()` puts a job on the queue; a worker must be running for the events to
reach Relay. By default the job goes on the app's default connection and queue; set
`RELAY_PUBLISH_CONNECTION` and `RELAY_PUBLISH_QUEUE` to move it. See
[Publishing events](../core-concepts/publishing-events.md).

## Project

Most calls act for one project. You do not have to name one: with nothing set, a project
key acts for its own project and an organization key for the project `default`, which
Relay makes on first use. To act for another, name it per call with
`Relay::forProject($ref)` or once with `RELAY_PROJECT`. See
[Authentication](../core-concepts/authentication.md).

## Config

```bash
php artisan vendor:publish --tag=relay-config
```

Every key is in the [configuration reference](../configuration/reference.md).

## Mail and webhooks

Both are optional:

- To send your app's mail through Relay, add a `relay` mailer:
  [Outbound mail](../core-concepts/outbound-mail.md#the-mail-transport).
- To receive Relay's webhooks, set `RELAY_WEBHOOK_SECRET` and use the `relay.webhook`
  middleware: [Webhooks from Relay](../core-concepts/incoming-webhooks.md).

## Check it

```php
use Cbox\Relay\Client\Facades\Relay;

Relay::whoami()->organization->name;
```
