---
title: Webhooks from Relay
description: Verify a webhook Relay delivers with the relay.webhook middleware, and read it as a typed IncomingWebhook and one of Relay's own events.
weight: 32
---

# Webhooks from Relay

Relay tells you what it does through the same webhook endpoints your customers use.
Register an endpoint of your own and subscribe it to `mail.*`, `push.*`,
`endpoint.disabled` or `project.outbound_paused`:

```php
$endpoint = Relay::forProject($ref)->endpoints()->register('https://app.example/hooks/relay', ['mail.*', 'push.*', 'endpoint.disabled', 'project.outbound_paused']);

// $endpoint->secret is given once: store it as RELAY_WEBHOOK_SECRET.
```

## The middleware

```php
use Cbox\Relay\Client\Webhooks\IncomingWebhook;
use Cbox\Relay\Client\Webhooks\Events\MailBounced;
use Cbox\Relay\Client\Webhooks\Events\MailReceived;

Route::post('/hooks/relay', function (Request $request) {
    $webhook = IncomingWebhook::from($request);   // verified and parsed by the middleware

    match (true) {
        ($event = $webhook->event()) instanceof MailReceived => ProcessInbound::dispatch($event->id),
        $event instanceof MailBounced => MarkBounced::dispatch($event->id, $event->recipient, $event->permanent),
        default => null,
    };

    return response()->noContent();
})->middleware('relay.webhook');
```

Exclude the route from CSRF protection, and answer 2xx quickly: do the work in a job.

`relay.webhook` checks the `cbox` signature over the raw body and answers:

| Case | Status |
|------|--------|
| a valid signature and a readable envelope | passes on to the route |
| a missing, malformed, stale or wrong signature | 401 |
| signed, but not an envelope Relay sends | 400 |
| no secret configured | 500, so Relay keeps retrying while you fix it |

| Key | Env | Default | |
|-----|-----|---------|---|
| `relay.webhook.secret` | `RELAY_WEBHOOK_SECRET` | none | the endpoint's signing secret |
| `relay.webhook.previous_secret` | `RELAY_WEBHOOK_PREVIOUS_SECRET` | none | the old secret while a rotation is under way |
| `relay.webhook.tolerance` | `RELAY_WEBHOOK_TOLERANCE` | `300` | seconds the timestamp may be off, either way |

### Rotating the secret

`Relay::endpoints()->rotateSecret($id)` gives a new secret; Relay signs with it from
then on. Move the old value to `RELAY_WEBHOOK_PREVIOUS_SECRET` and put the new one in
`RELAY_WEBHOOK_SECRET` before you rotate where you can, or right after: a delivery
signed with either is accepted. Remove the previous one after
`$endpoint->previousSecretExpiresAt`.

### One secret per project

Each endpoint has its own secret. If every project has an endpoint at your app, bind your
own `Contracts\WebhookSecrets`; see
[Extension points](../extension-points/_index.md#webhook-secrets-per-request).

## The signature

`X-Cbox-Signature: t=<unix>,v1=<hex>`, where the hex is HMAC-SHA256 over
`"{t}.{body}"` with the secret as the key, exactly as written.

- The timestamp that counts is the `t=` inside the header: it is signed. The separate
  `X-Cbox-Timestamp` header is not, and is never read.
- The window is two-sided: a delivery dated too far in the future is refused like one
  that is too old.
- The MAC is PHP's `hash_hmac()` and the comparison `hash_equals()`.
- `Cbox\Relay\Client\Webhooks\WebhookSignature::verify($body, $header, $secrets, $tolerance)`
  is the same check without the middleware; it throws `RelayWebhookRejected` with a
  `->reason`.

This verifies the `cbox` scheme only. An endpoint registered with
`SignatureScheme::StandardWebhooks` is signed differently; verify those with a Standard
Webhooks library.

The check is tested against a signature produced by `cboxdk/laravel-webhook-signature`,
the implementation Relay signs with, and has not been run against a delivery from the
live service.

The window makes a captured delivery expire; within it, the same delivery can still be
posted twice. Deduplicate on `$webhook->deliveryId`.

## The envelope

`IncomingWebhook::parse($body)` reads either envelope an endpoint can choose, the `cbox`
one or CloudEvents 1.0 structured JSON, into the same object:

| Property | `cbox` | CloudEvents | |
|----------|--------|-------------|---|
| `id` | `id` | `id` | the event: the same across endpoints, retries and replays |
| `type` | `type` | `type` | |
| `data` | `data` | `data` | the payload, untouched |
| `occurredAt` | `created_at` | `time` | |
| `deliveryId` | `delivery_id` | `deliveryid` | unique per event and endpoint: deduplicate on it |
| `attempt` | `attempt` | `attempt` | |
| `sequence` | `sequence` | `sequence` | counts up by one per endpoint; a gap is a delivery you have not seen |
| `projectId` | `project.key` | the last part of `subject` | Relay's `prj_…` id |
| `apiVersion` | `api_version` | `apiversion` | |
| `envelope` | | | `Envelope::Cbox` or `Envelope::CloudEvents` |

Every delivery also carries `X-Webhook-Event`, `X-Webhook-Delivery` (the delivery id
again, also sent as `Idempotency-Key`), `X-Webhook-Attempt` and `X-Webhook-Sequence`.
The parser reads the body only: the body is what is signed.

One delivery carries one event. Batched deliveries are not read: Relay's API has no way
to ask for them.

Parsing proves nothing about who sent the body. Verify first.

## Relay's own events

`$webhook->event()` returns a typed object for Relay's own types and `null` for every
other type (your own events come back in `$webhook->data`). All are in
`Cbox\Relay\Client\Webhooks\Events`.

| Type | Class | Carries |
|------|-------|---------|
| `mail.received` | `MailReceived` | `id`, `projectId`, `recipient`, `envelopeFrom`, `from`, `subject`, `kind`, `autoReply`, `attachments`, `size`, `receivedAt` |
| `mail.accepted` | `MailAccepted` | `id`, `projectId`, `from`, `recipients`, `suppressed`, `status`, `reason` |
| `mail.blocked` | `MailBlocked` | the same |
| `mail.delivered` | `MailDelivered` | `id`, `projectId`, `recipient`. The recipient's server accepted the mail; it says nothing about inbox or junk |
| `mail.bounced` | `MailBounced` | `id`, `projectId`, `recipient`, `permanent`, `status`, `diagnostic` |
| `mail.complained` | `MailComplained` | `id`, `projectId`, `recipient` |
| `mail.clicked` | `MailClicked` | `id`, `projectId`, `linkId`, `url`, `clickedAt`. No recipient: every recipient of a mail gets the same link |
| `push.delivered` | `PushDelivered` | `id`, `projectId`, `subscriptionId`, `externalRef`, `attempts` |
| `push.expired` | `PushExpired` | the same |
| `endpoint.disabled` | `EndpointDisabled` | `endpointId`, `projectId`, `reason` |
| `project.outbound_paused` | `ProjectOutboundPaused` | `projectId`, `reason`, `sent`, `bounced`, `complained` |

`mail.received` carries no content: fetch it with `Relay::inbound()->find($event->id)`.

These are the fields Relay's webhook guide lists for each event. A field that is missing
or of the wrong type makes `event()` throw `RelayWebhookRejected` (`malformed_body`).

An event a [source](inbound-webhooks.md) forwards has the type `inbound.<name>` and is
not one of these: read it from `$webhook->data`.

## Testing your route

```php
use Cbox\Relay\Client\Webhooks\WebhookSignature;

config()->set('relay.webhook.secret', 'test-secret');

$body = json_encode(['id' => 'evt_1', 'type' => 'mail.complained', 'delivery_id' => 'd_1', 'data' => ['id' => 'msg_1', 'project_id' => 'prj_1', 'recipient' => 'ann@example.com']]);

$this->call('POST', '/hooks/relay', server: ['HTTP_X_CBOX_SIGNATURE' => WebhookSignature::header($body, 'test-secret'), 'CONTENT_TYPE' => 'application/json'], content: $body)
    ->assertNoContent();
```
