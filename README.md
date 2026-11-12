# Cbox Relay client

The Cbox Relay client for Laravel. Cbox Relay delivers webhooks, mail and push for Cbox
products; this package is how a Laravel app talks to its API. It covers what the service
has today:

- **Who am I.** `Relay::whoami()`: the organization, the environment, the key's abilities.
- **Scopes.** `Relay::scopes()->put()`, `find()`, `list()`: register each of your own
  workspaces with Relay under your own id, idempotently.
- **The delivery log.** `Relay::deliveries()`: filtered and cursor-paginated.
- **A fake.** `Relay::fake()` keeps all of it in memory for your tests.

Publishing events and sending mail are **not built yet**: the service does not have them
yet either. See [BUILD-STATUS.md](BUILD-STATUS.md).

Documentation: [docs/index.md](docs/index.md). Start with the
[quickstart](docs/quickstart.md).

## Install

```bash
composer require cboxdk/laravel-relay-client
```

```dotenv
RELAY_URL=https://api.cboxrelay.com
RELAY_KEY=rlk_live_…
```

With either empty every call throws `RelayNotConfigured`. Publish the config with
`php artisan vendor:publish --tag=relay-config` for the timeouts and the default scope
(`config/relay.php`).

## Use

```php
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Facades\Relay;

Relay::whoami()->can('scopes:manage');

// One scope per workspace, under your own id. Safe to call every time.
$scope = Relay::scopes()->put($workspace->id, name: $workspace->name, settings: ['region' => 'eu']);
$scope->created;   // true the first time

Relay::scopes()->find($workspace->id);          // ?Scope
Relay::scopes()->list(limit: 50, cursor: null); // Page<Scope>

// The log of one workspace, with an organization key acting for it.
$page = Relay::forScope($workspace->id)->deliveries(new DeliveryFilter(status: 'failed'), limit: 20);

foreach ($page as $delivery) {
    $delivery->target; $delivery->responseCode; $delivery->occurredAt;
}

$page->nextCursor;   // pass back as cursor: for the next page, null on the last
```

Relay's errors are typed: `RelayUnauthorized`, `RelayForbidden`, `RelayNotFound`,
`RelayConflict`, `RelayInvalid` (`->errors`), `RelayRateLimited` (`->retryAfter`) and
`RelayUnavailable`, all extending `RelayException`.

## Testing

```php
use Cbox\Relay\Client\Facades\Relay;

$relay = Relay::fake();

$this->post('/workspaces', ['name' => 'Acme']);

$relay->assertScopePut($workspace->id, fn ($call) => $call->name === 'Acme');
```

## Development

```bash
composer qa    # pint, phpstan (max), pest, licenses, audit, sbom
```

MIT. See [LICENSE.md](LICENSE.md) and [SECURITY.md](SECURITY.md).
