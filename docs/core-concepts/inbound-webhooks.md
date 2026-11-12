---
title: Inbound webhooks
description: Let Relay take a provider's webhooks for a project: create a source, give its URL to the provider, and receive each event from Relay on your own endpoint.
weight: 33
---

# Inbound webhooks

Point a provider (Stripe, GitHub, another Cbox product) at Relay instead of at your
app. Relay verifies each call with the provider's own scheme and secret, drops the
provider's repeats, and forwards each new event to the project's endpoints like any other
event: retried, logged, replayable. Your app does not have to be up when the provider
calls.

## Sources

```php
use Cbox\Relay\Client\Data\SourceScheme;

$sources = Relay::forProject($ref)->sources();

$source = $sources->register('stripe', SourceScheme::Stripe, $secretTheProviderShowedYou);

$source->url;         // give this to the provider as the webhook address
$source->eventType;   // 'inbound.stripe': what its webhooks are forwarded as
$source->status;      // 'active' or 'paused'

$sources->list();                          // list<Source>
$sources->find($id);                       // ?Source
$sources->rotateSecret($id, $newSecret);   // the old one verifies until ->previousSecretExpiresAt (24 hours)
$sources->pause($id);                      // its URL answers the provider 404 while paused
$sources->resume($id);
$sources->delete($id);                     // its URL answers 404 from then on
```

- `name` is yours: lower-case letters, digits and hyphens, at most 40, not starting or
  ending with a hyphen. It names the event type.
- `scheme` is how the provider signs: `SourceScheme::Stripe`, `Github`, `Shopify`,
  `Slack`, `Mailgun`, `StandardWebhooks` or `Cbox`.
- The secret is the provider's signing secret. Relay keeps it sealed and never returns
  it; no call of this package gives it back. The parameter is marked sensitive, so it
  stays out of stack traces.
- `Source::$lastReceivedAt` tells when the provider last got through.

Needs `sources:manage`.

## Receiving what a source forwards

Subscribe an endpoint of your own to the source's event type:

```php
Relay::forProject($ref)->endpoints()->register('https://app.example/hooks/relay', ['inbound.stripe']);
```

It arrives signed by Relay, not by the provider, so the
[`relay.webhook` middleware](incoming-webhooks.md) verifies it like every other
delivery. The provider's signature was checked by Relay already.

```php
$webhook = IncomingWebhook::from($request);

if ($webhook->type === 'inbound.stripe') {
    $webhook->data['source'];              // 'stripe'
    $webhook->data['provider_event_id'];   // the provider's own id for the event
    $webhook->data['content_type'];        // 'application/json'
    $webhook->data['body'];                // what the provider sent
}
```

`$webhook->event()` is `null` for these: the typed objects cover Relay's own events,
and what a provider sends is the provider's.

What the provider itself is answered (202, 401, 404, 413) and the 200 KB limit are the
service's side; see Relay's own documentation.

## In a test

```php
$relay = Relay::fake()->acceptAnyProject();

$this->post('/integrations/stripe', ['secret' => 'whsec_…']);

$relay->assertSourceRegistered('stripe', fn (Source $source, string $secret): bool => $source->scheme === 'stripe' && $secret === 'whsec_…');
```

The fake stores sources; no provider ever calls one, and nothing is forwarded.
