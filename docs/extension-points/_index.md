---
title: Extension points
description: Replace or decorate the transport, extend the manager, and what is deliberately open.
weight: 40
---

# Extension points

## Replace or decorate the transport

Everything goes through `Cbox\Relay\Client\Contracts\RelayTransport`. Bind your own
implementation in a service provider to add logging, metrics, retries or caching:

```php
use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Http\HttpRelayTransport;

$this->app->bind(RelayTransport::class, fn ($app) => new RetryingTransport(
    $app->make(HttpRelayTransport::class),
));
```

A decorator implements the five methods of the contract and forwards them. The manager
and the facade pick it up, because the container builds the manager on whatever is bound.

## Customize the HTTP client

`HttpRelayTransport` uses Laravel's HTTP client factory, so the factory's global
middleware and options apply to it like to any other request your app makes.

## Extend the manager

`Cbox\Relay\Client\Relay` and `Cbox\Relay\Client\Scopes` are ordinary, non-final
classes. Bind a subclass as `Cbox\Relay\Client\Relay` to add methods of your own.

## Classes are open

No class in this package is `final`. The value objects are `readonly`, which a mocking
library cannot subclass; build them instead. Their constructors are public and cheap,
`new Page` and `new DeliveryFilter` are valid as they are, and every exception can be
built without arguments.

## Not extension points yet

There is no event publishing and no mail driver to hook into: neither is built. See
[BUILD-STATUS.md](https://github.com/cboxdk/laravel-relay-client/blob/main/BUILD-STATUS.md).
