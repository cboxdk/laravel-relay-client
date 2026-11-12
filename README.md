# Cbox Relay client

The Cbox Relay client for Laravel. Cbox Relay delivers webhooks, mail and push for Cbox
products; this package is how a Laravel app talks to its API.

- **Publish events.** `Relay::publish()` queues a job that calls Relay after commit and
  retries it, with an idempotency key so a retry publishes once.
- **Webhook endpoints.** Declare event types; register, pause, rotate, ping and replay
  endpoints; read events and what became of each delivery.
- **Mail.** Sending domains, the suppression list, `Relay::sendMail()`, and a `relay`
  mail transport so `MAIL_MAILER=relay` works.
- **Web Push.** The scope's key, browser subscriptions, `Relay::push()`, and a Laravel
  notification channel.
- **Inbound mail.** Claim addresses, read received mail, erase it.
- **The judge.** Score a mail or a template without sending it.
- **Inbound webhooks.** Sources that take a provider's webhooks for a scope.
- **Erasure.** Publish with a `subject`; `Relay::eraseSubject()` forgets that person.
- **Webhooks from Relay.** A `relay.webhook` middleware that verifies a delivery, and
  typed objects for Relay's own events.
- **Scopes, whoami, the delivery log**, typed errors, and **a fake** for your tests.

The client covers Relay's API. What the service behind it runs is Relay's own: its API
guides say the mail servers are not running yet, so today no mail leaves Relay and none
arrives. See Relay's own status, and [BUILD-STATUS.md](BUILD-STATUS.md) for what this
package has and has not verified.

Documentation: [docs/index.md](docs/index.md). Start with the
[quickstart](docs/quickstart.md).

## Install

```bash
composer require cboxdk/laravel-relay-client
```

```dotenv
RELAY_URL=https://api.cboxrelay.com
RELAY_KEY=rlk_live_…
RELAY_SCOPE=workspace-42        # optional: the scope every call acts for
```

With `RELAY_URL` or `RELAY_KEY` empty every call throws `RelayNotConfigured`. Publish
the config with `php artisan vendor:publish --tag=relay-config`; every key is in the
[configuration reference](docs/configuration/reference.md).

`Relay::publish()` needs a queue worker.

## Use

```php
use Cbox\Relay\Client\Facades\Relay;

// One scope per workspace, under your own id. Safe to call every time.
Relay::scopes()->put($workspace->id, name: $workspace->name);

$relay = Relay::forScope($workspace->id);

// Webhooks out
$relay->eventTypes()->declare(['order.paid', 'order.refunded']);
$endpoint = $relay->endpoints()->register('https://customer.example/hooks', ['order.*']);
$endpoint->secret;   // given once

$relay->publish('order.paid', ['id' => $order->id], subject: "contact_{$contact->id}");   // queued, after commit, retried
$relay->publishNow('order.paid', ['id' => $order->id])->id;       // one HTTP call, now

$relay->eraseSubject("contact_{$contact->id}")->removed;         // forget one person's events

// Webhooks in
$relay->sources()->register('stripe', SourceScheme::Stripe, $providerSecret)->url;

// Mail
$relay->sendMail(new OutgoingMail(new MailAddress('hello@acme.dk', 'Acme'), ['ann@example.com'], 'Your order', text: 'Thanks.'));

// Web Push
$relay->push($subscriptionId, ['title' => 'Your order shipped']);

// The judge
$relay->judge(html: $html, subject: 'Welcome')->score('phishing');

// Inbound mail
$relay->inbound()->list();
```

Most calls act for one scope. Without one (`forScope()` or `RELAY_SCOPE`) they throw
`RelayScopeRequired` before anything is sent; with a scope key, set
`RELAY_SCOPE_KEY=true`.

Relay's errors are typed: `RelayUnauthorized`, `RelayForbidden`, `RelayNotFound`,
`RelayConflict`, `RelayTooLarge`, `RelayInvalid` (`->errors`), `RelayRateLimited`
(`->retryAfter`) and `RelayUnavailable`, all extending `RelayException`.

### Laravel's mailer

```php
// config/mail.php
'mailers' => ['relay' => ['transport' => 'relay']],
```

```dotenv
MAIL_MAILER=relay
```

### Receiving Relay's webhooks

```php
Route::post('/hooks/relay', function (Request $request) {
    $event = IncomingWebhook::from($request)->event();   // MailBounced, PushExpired, …

    return response()->noContent();
})->middleware('relay.webhook');   // verifies X-Cbox-Signature with RELAY_WEBHOOK_SECRET
```

## Testing

```php
use Cbox\Relay\Client\Facades\Relay;

$relay = Relay::fake()->acceptAnyScope();   // publish() records at once: no queue, no HTTP

$this->post("/orders/{$order->id}/pay");

$relay->assertPublished('order.paid', fn (NewEvent $event) => $event->payload['id'] === $order->id);
$relay->assertMailSent(fn (OutgoingMail $mail) => $mail->to === ['ann@example.com']);
$relay->assertPushed($subscriptionId);
```

See [Testing](docs/getting-started/testing.md).

## Development

```bash
composer qa    # pint, phpstan (max), pest, licenses, audit, sbom
```

MIT. See [LICENSE.md](LICENSE.md) and [SECURITY.md](SECURITY.md).
