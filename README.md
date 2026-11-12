# Cbox Relay client

The Cbox Relay client for Laravel. Cbox Relay delivers webhooks, mail and push for Cbox
products; this package is how a Laravel app talks to its API.

- **Publish events.** `Relay::publish()` queues a job that calls Relay after commit and
  retries it, with an idempotency key so a retry publishes once.
- **Webhook endpoints.** Declare event types; register, pause, rotate, ping and replay
  endpoints; read events and what became of each delivery.
- **Mail.** Sending domains, the suppression list, `Relay::sendMail()` and a `relay`
  mail transport so `MAIL_MAILER=relay` works. Tags and metadata that come back in every
  event, inline images, a send time with cancel, up to 100 mails in one call, a list with
  filters, and click and open tracking per domain or per mail.
- **Web Push.** The project's key, browser subscriptions, `Relay::push()`, and a Laravel
  notification channel.
- **Inbound mail.** Claim one address, a whole domain, or an address of Relay's that
  yours forwards to; read received mail, erase it.
- **The judge.** Score a mail or a template without sending it.
- **Inbound webhooks.** Sources that take a provider's webhooks for a project.
- **Erasure.** Publish with a `subject`; `Relay::eraseSubject()` forgets that person.
- **Webhooks from Relay.** A `relay.webhook` middleware that verifies a delivery, typed
  objects for Relay's own events, and an opt-in route (`Relay::webhookRoutes()`) that
  handles each delivery once and dispatches a Laravel event for it.
- **Know what happened to a mail.** An optional local store: a row per sent mail and per
  event about it, kept up to date from the webhooks. Off by default.
- **Commands.** `relay:install`, `relay:doctor`, `relay:ping`, `relay:tail`, and a health
  check for cboxdk/laravel-health.
- **Several connections.** More than one key from one app: `Relay::connection('billing')`.
- **Sending limits and holds.** `Relay::sendingLimits()` reads how much a project may send
  and asks for more; a refusal for a limit says whose limit it was and how long to wait;
  a mail says whether the project or Relay's own guard holds it.
- **Projects, whoami, the delivery log**, typed errors, and **a fake** for your tests.

The client covers Relay's API. Relay's mail servers run since 12 November 2026: mail sent
through Relay is delivered, and mail to a receiving address arrives. See
[BUILD-STATUS.md](BUILD-STATUS.md) for what this package has and has not verified.

Documentation: [docs/index.md](docs/index.md). Start with the
[quickstart](docs/quickstart.md).

## Install

```bash
composer require cboxdk/laravel-relay-client
```

```dotenv
RELAY_URL=https://api.cboxrelay.com
RELAY_KEY=rlk_live_…
RELAY_PROJECT=workspace-42        # optional: the project every call acts for
```

With `RELAY_URL` or `RELAY_KEY` empty every call throws `RelayNotConfigured`. Publish
the config with `php artisan vendor:publish --tag=relay-config`; every key is in the
[configuration reference](docs/configuration/reference.md).

`Relay::publish()` needs a queue worker.

## Use

```php
use Cbox\Relay\Client\Facades\Relay;

// One project per workspace, under your own id. Safe to call every time.
Relay::projects()->put($workspace->id, name: $workspace->name);

$relay = Relay::forProject($workspace->id);

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

Most calls act for one project. Name it with `forProject()` or `RELAY_PROJECT`, or name
none: a project key then acts for its own project, and an organization key for the project
`default`, which Relay makes on first use.

Relay's errors are typed: `RelayUnauthorized`, `RelayForbidden`, `RelayNotFound`,
`RelayConflict` (`->type()` names Relay's reason, e.g. `mail_not_held`), `RelayTooLarge`, `RelayInvalid` (`->errors`), `RelayRateLimited`
(`->retryAfter`) and `RelayUnavailable`, all extending `RelayException`.

### Laravel's mailer

```php
// config/mail.php
'mailers' => ['relay' => ['transport' => 'relay']],
```

```dotenv
MAIL_MAILER=relay
```

#### Failover

Put Relay first in Laravel's `failover` mailer and another mailer behind it:

```php
// config/mail.php
'mailers' => [
    'relay' => ['transport' => 'relay'],
    'resend' => ['transport' => 'resend'],
    'failover' => ['transport' => 'failover', 'mailers' => ['relay', 'resend']],
],
```

```dotenv
MAIL_MAILER=failover
```

Laravel's failover moves on only for a Symfony `TransportException`. The `relay`
transport throws one, with the Relay exception as its previous, when Relay cannot take
the mail right now or not with this key:

| Relay's answer | Exception (previous) | Failover |
|----------------|----------------------|----------|
| `RELAY_URL` or `RELAY_KEY` empty | `RelayNotConfigured` | moves on |
| unreachable, timeout | `RelayUnavailable` | moves on |
| 5xx | `RelayUnavailable` | moves on |
| 429 (rate or sending limit) | `RelayRateLimited` | moves on |
| 401, 403 (the key) | `RelayUnauthorized`, `RelayForbidden` | moves on |
| 422 (the mail is invalid) | `RelayInvalid`, thrown as it is | stops, the send fails |
| 413 (the mail is too large) | `RelayTooLarge`, thrown as it is | stops, the send fails |

When Relay refuses the mail itself, the next mailer would send the same bad mail, so the
send fails loudly instead. Any other Relay error (404, 409, an unreadable answer) is
thrown as it is too. Since 0.2.2; with 0.2.1 and earlier the failover never moved on.

Give `X-Relay-Idempotency-Key` a stable value per mail (`"invoice:{$id}"`), not a random
one per attempt, so a retried or re-sent mail reaches Relay with the same key and goes
out once.

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

$relay = Relay::fake()->acceptAnyProject();   // publish() records at once: no queue, no HTTP

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
