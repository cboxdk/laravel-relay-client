---
title: Extension points
description: Replace or decorate the transport, the publisher and the webhook secrets, extend the manager, and what is deliberately open.
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

A decorator implements every method of the contract and forwards them. The manager, the
facade, the publish job, the mail transport and the notification channel pick it up,
because each resolves the contract from the container when it is used.

## Replace the publisher

`Relay::publish()` hands the event to `Cbox\Relay\Client\Contracts\EventPublisher`:

```php
interface EventPublisher
{
    public function publish(NewEvent $event, ?string $scope = null): void;
}
```

The default, `Publishing\QueuedEventPublisher`, dispatches `Jobs\PublishEvent` after
commit. `Publishing\ImmediateEventPublisher` calls the transport at once
(`relay.publish.sync`, and under `Relay::fake()`). Bind your own to write to an outbox
table in the same transaction, or to dispatch on another bus:

```php
$this->app->bind(EventPublisher::class, OutboxEventPublisher::class);
```

The event always arrives with an idempotency key, so your implementation may deliver it
more than once without publishing twice.

`Jobs\PublishEvent` is an ordinary job class: subclass it, or dispatch it yourself with
`PublishEvent::for(new NewEvent(…), $scope, $tries, $backoff)`.

## Webhook secrets per request

The `relay.webhook` middleware asks `Cbox\Relay\Client\Contracts\WebhookSecrets` which
secrets a request may be signed with. The default reads `relay.webhook`. When each scope
has its own endpoint and secret, bind your own:

```php
use Cbox\Relay\Client\Contracts\WebhookSecrets;

class WorkspaceWebhookSecrets implements WebhookSecrets
{
    public function for(Request $request): array
    {
        $workspace = Workspace::find($request->route('workspace'));

        return array_filter([$workspace?->relay_secret, $workspace?->relay_previous_secret]);
    }
}

$this->app->bind(WebhookSecrets::class, WorkspaceWebhookSecrets::class);
```

An empty list refuses the delivery with 500. Note that the resolver runs before the
signature is checked: whatever it reads from the request to pick a secret is unverified.

## Customize the HTTP client

`HttpRelayTransport` uses Laravel's HTTP client factory, so the factory's global
middleware and options apply to it like to any other request your app makes.

## Extend the manager

`Cbox\Relay\Client\Relay` and the objects it returns (`Scopes`, `Endpoints`, `Events`
and the others) are ordinary, non-final classes. Bind a subclass as
`Cbox\Relay\Client\Relay` to add methods of your own.

## Classes are open

No class in this package is `final`. The value objects are `readonly`, which a mocking
library cannot subclass; build them instead. Their constructors are public, and every
exception can be built without arguments.
