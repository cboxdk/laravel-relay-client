---
title: Connections
description: Talk to Relay with more than one key from one app, each connection with its own key, project and webhook secret.
weight: 35
---

# Connections

Most apps have one Relay key, and the top level of `config/relay.php` is all they need.
That is the connection named `default`.

An app that uses more than one key (two organizations, or live and test side by side)
names the others:

```php
// config/relay.php
'connections' => [
    'billing' => [
        'key' => env('RELAY_BILLING_KEY'),
        'project' => env('RELAY_BILLING_PROJECT'),
        'project_key' => false,
        'webhook' => ['secret' => env('RELAY_BILLING_WEBHOOK_SECRET')],
    ],
],
```

A connection has its own `key`, `project`, `project_key` and `webhook.secret` /
`webhook.previous_secret`. Its `url`, `timeout` and `connect_timeout` fall back to the
top level when it does not set them.

## Use one

```php
use Cbox\Relay\Client\Facades\Relay;

Relay::connection('billing')->sendMail($mail);
Relay::connection('billing')->forProject('ws_42')->publish('invoice.paid', ['id' => $id]);
Relay::connection('billing')->connectionName();   // 'billing'; null on the default
```

`Relay::connection('nope')` throws `RelayNotConfigured`. So does the first call on a
connection that has no key.

A published event keeps its connection: the queued job sends it with that connection's
key, whichever worker runs it.

## A mailer per connection

```php
// config/mail.php
'mailers' => [
    'relay' => ['transport' => 'relay'],
    'billing' => ['transport' => 'relay', 'connection' => 'billing', 'project' => 'ws_42'],
],
```

## Webhooks per connection

[The package's webhook route](incoming-webhooks.md#the-packages-route-and-laravel-events)
answers `POST relay/webhook` for the default connection and `POST relay/webhook/billing`
for a named one, which is verified with that connection's secret. The Laravel event
carries the name (`$event->connection`).

## In tests and behind a decorator

`Relay::fake()` puts every connection on the one fake: `Relay::connection('billing')`
records on it, and publishes at once.

When you bind your own `RelayTransport` (a decorator), every connection goes through it.
To give one connection a transport of its own, bind it as `relay.transport.{name}`:

```php
$this->app->bind('relay.transport.billing', fn ($app) => new LoggingTransport(
    new HttpRelayTransport($app->make(RelayConfig::class), $app->make(Factory::class), 'billing'),
));
```
