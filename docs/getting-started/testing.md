---
title: Testing
description: Fake Relay in your tests with Relay::fake(), FakeRelay and the InteractsWithRelay trait, as the package's own suite does.
weight: 12
---

# Testing

`Cbox\Relay\Client\Testing\FakeRelay` is Relay in memory. It implements the transport
contract: scopes, event types, endpoints, events, judgments, inbound routes, push
subscriptions, sending domains and suppressions are really stored, per scope; published
events, sent mail and push messages are recorded and go nowhere; every call is
recorded. The package's own suite runs on it; if the fake is awkward for your test,
that is a bug in the fake.

## Setup

```php
use Cbox\Relay\Client\Facades\Relay;

$relay = Relay::fake();   // nothing leaves the process from here on
```

`Relay::fake()` binds the fake as `RelayTransport`, so the facade, an injected
`Cbox\Relay\Client\Relay`, an injected `RelayTransport`, the `relay` mail transport and
the notification channel all reach the same fake. It also makes **`Relay::publish()`
record on the fake at once instead of putting a job on the queue**, so
`assertPublished()` holds right after the call and no worker is needed.

An object that was built with the manager or the transport *before* `fake()` keeps the
real one, so fake first. Pass your own instance to prepare it beforehand:
`Relay::fake($prepared)`.

Or with the trait, in your `TestCase`:

```php
use Cbox\Relay\Client\Testing\InteractsWithRelay;

abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    use InteractsWithRelay;
}

$relay = $this->fakeRelay();
```

## Scopes in a test

A call that acts for a scope the fake does not know answers 404, as the real service
does. Either store the scope first or tell the fake not to care:

```php
$relay->seedScope('ws_1');     // one scope
$relay->acceptAnyScope();      // or: any scope a call names is stored on first use
```

A call that needs a scope and has none still throws `RelayScopeRequired`: that check is
in the manager, in front of the fake.

## Publishing

```php
use Cbox\Relay\Client\Data\NewEvent;

$relay = Relay::fake()->acceptAnyScope();

$this->post("/orders/{$order->id}/pay");

$relay->assertPublished('order.paid');
$relay->assertPublished('order.paid', fn (NewEvent $event, ?string $scope): bool => $event->payload['id'] === $order->id && $scope === $workspace->id);
$relay->assertPublishedTimes('order.paid', 1);
$relay->assertNotPublished('order.refunded');
$relay->assertNothingPublished();
```

| Method | What it checks |
|--------|----------------|
| `assertPublished(string $type, ?callable $where = null)` | An event of that type was published; `$where` gets the `NewEvent` and the scope. |
| `assertPublishedTimes(string $type, int $times)` | Exactly that often. A repeat of an idempotency key is not a publish. |
| `assertNotPublished(string $type)` | Never. |
| `assertNothingPublished()` | No event at all. |
| `assertEventTypesDeclared(array $types, ?string $scope = null)` | The scope's declared types are exactly these names. |
| `assertEndpointRegistered(string $url, ?callable $where = null)` | An endpoint with that URL exists; `$where` gets the `Endpoint`. |
| `assertSubjectErased(string $subject, ?string $scope = null)` | An erasure was asked for that subject (for that scope). |
| `assertNoSubjectErased()` | No erasure at all. |
| `assertSourceRegistered(string $name, ?callable $where = null)` | A source with that name exists; `$where` gets the `Source` and its current secret. |
| `assertNoSourceRegistered()` | No source exists. |

`published(?string $type = null): list<NewEvent>` gives the events themselves.

Helpers: `seedEndpoint(string $scope, string $url = …, array $eventTypes = ['*'], string $status = 'active')`,
`markWebhookDelivery(string $id, string $status, ?int $responseCode = null, ?string $error = null)`
`pingAnswers(PingResult $result)` and
`seedSource(string $scope, string $name, SourceScheme $scheme = StandardWebhooks, string $secret = …, string $status = 'active')`.

To test the queued path itself (that a job is dispatched, on which queue), do not fake
Relay: use `Bus::fake()` and assert on `Cbox\Relay\Client\Jobs\PublishEvent`.

## Mail

```php
use Cbox\Relay\Client\Data\OutgoingMail;

$relay->assertMailSent();
$relay->assertMailSent(fn (OutgoingMail $mail, ?string $scope): bool => $mail->to === ['ann@example.com'] && $mail->subject === 'Your order');
$relay->assertMailSentTimes(1);
$relay->assertNoMailSent();
```

A held mail: `markMail($id, 'quarantined', $reason)` holds one, then

```php
$relay->assertMailReleased($mail->id);
$relay->assertMailDiscarded($mail->id);
```

This covers `Relay::sendMail()` and everything sent through the `relay` mail transport,
so a mailable is asserted the same way. `sentMail(): list<OutgoingMail>` gives the mails.

Helpers: `seedDomain(string $scope, string $domain, string $status = 'verified')`,
`seedSuppression(string $scope, string $address, SuppressionReason $reason = Manual, ?string $source = null)`,
`markMail(string $id, string $status, ?string $reason = null)`,
`markRecipient(string $mailId, string $address, string $status, ?string $detail = null)`
and `dnsPublished(bool $published = true)` (whether a verify finds its DNS record; it
does by default).

## Push

```php
use Cbox\Relay\Client\Testing\SentPush;

$subscription = $relay->seedPushSubscription('ws_1', ref: 'user-1');

$user->notify(new OrderShipped);

$relay->assertPushed();
$relay->assertPushed($subscription->id, fn (SentPush $push): bool => $push->payload['title'] === 'Your order shipped');
$relay->assertPushedTimes(1);
$relay->assertNothingPushed();
```

A `SentPush` has `subscription`, `payload`, `ttl`, `urgency`, `topic` and `scope`.
`pushed(): list<SentPush>` gives them all. `markPush(string $id, string $status, ?int $responseCode = null, ?string $error = null)`
sets what became of a message; `expired` also removes the subscription.

## The judge

```php
use Cbox\Relay\Client\Data\JudgeInput;

$relay->judgeAs(['phishing' => 0.9], category: 'marketing');   // what new judgments answer

$relay->assertJudged();
$relay->assertJudged(fn (JudgeInput $input, ?string $scope): bool => $input->template?->id === 'welcome');
$relay->assertNothingJudged();
```

Without `judgeAs()` every verdict scores 0 and the category is `other`. The fake scores
nothing by itself.

## Inbound mail

```php
$mail = $relay->seedInbound('ws_1', recipient: 'hello@acme.dk', from: 'ann@example.com', subject: 'Hi', text: 'Hi there');

$relay->assertInboundErased($mail->id);
$relay->assertInboundRouteRegistered('*@acme.dk');
```

`seedInbound()` also takes `html`, `kind`, `attachments`, `headers`, `authentication`,
`receivedAt` and `status`. `activateInboundRoute(string $id)` makes a pending route
active, as an operator approving a prefix would.

## Scopes and the delivery log

| Method | What it does |
|--------|--------------|
| `seedScope(string $externalRef, ?string $name = null, array $settings = [], ?DateTimeInterface $suspendedAt = null): Scope` | Stores a scope without recording a call. |
| `seedDeliveryFor(string $scope, string $status, string $channel = 'webhook', string $target = 'https://example.test/hooks', string $subjectType = 'event', ?string $subjectId = null, ?DateTimeInterface $occurredAt = null, int $attempt = 1, ?int $responseCode = null, ?string $error = null, ?int $durationMs = null): Delivery` | Adds one log line for a scope, storing the scope if it is not there. |
| `seedDeliveries(Delivery ...$deliveries): static` | Adds log lines exactly as given. |
| `actingAs(Identity $identity): static` | What `whoami` answers. The default is a test-environment organization key with every ability. |

| Method | What it checks |
|--------|----------------|
| `assertScopePut(string $externalRef, ?callable $where = null)` | That scope was put (by a call matching `$where`). |
| `assertScopePutTimes(string $externalRef, int $times)` | It was put exactly that often. |
| `assertScopeNotPut(string $externalRef)` | It was never put. |
| `assertNoScopePut()` | Nothing was written, whatever was read. |
| `assertDeliveriesRead(?callable $where = null)` | The delivery log was read (by a call matching `$where`). |
| `assertActedForScope(string $ref)` | At least one call sent that `Relay-Scope`. |
| `assertCalledTimes(Operation $operation, int $times)` | Exactly that many calls of one operation. |
| `assertNothingSent()` | No call at all: no read, no write. |

For your own assertions: `calls(?Operation $operation = null): list<RecordedCall>`,
`puts(string $externalRef)`, `scopes()` and `scope(string $ref)`. A `RecordedCall` has
`operation`, `actingScope`, `ref`, `name`, `settings`, `idempotencyKey`, `filter`,
`limit`, `cursor` and `subject` (the value object the call was made with, when it had
one). There is one `Operation` per method of the contract.

## Making Relay misbehave

```php
use Cbox\Relay\Client\Exceptions\RelayUnavailable;

$relay->failWith(new RelayUnavailable);   // every call is recorded, then throws this
$relay->recover();                        // back to answering
```

Any `RelayException` works: `new RelayRateLimited(retryAfter: 30)`,
`new RelayForbidden`, and so on. Every exception can be built without arguments.

## Webhooks from Relay

A route behind `relay.webhook` is tested with a real signature, not with the fake:
`WebhookSignature::header($body, $secret)` makes the header. See
[Webhooks from Relay](../core-concepts/incoming-webhooks.md#testing-your-route).

## Where the fake decides

Relay's API description leaves some things open, and a fake cannot do everything the
service does. The fake picks the following; treat them as the fake's choices, not as
promises about the service.

- A list read without `limit` returns up to 50 items (`FakeRelay::DEFAULT_LIMIT`).
- A scope put without a name is named after its external ref.
- A `Relay-Scope` that names no stored scope answers 404, unless `acceptAnyScope()`.
- A scoped call *without* a scope is taken as a scope key's call and gets a place of
  its own; the fake does not answer Relay's 422 `scope_required` for it.
- Acting for a scope narrows the delivery log and received mail to that scope and fills
  `whoami`'s `scope`; it does not change what the scope endpoints return.
- The delivery log is newest first, `from` and `to` include their bounds, and nothing
  the fake records adds a line to it.
- An `Idempotency-Key` repeated with the same request gives the first answer again; with
  a different request it answers 409 (`RelayConflict`). The fake keeps keys for its
  whole life and across scopes.
- A `limit` below 1, or a cursor the fake did not hand out, answers 422.
- **Rules that need data are enforced once the data is there.** A scope that has
  declared event types can only publish those; a scope with none can publish anything.
  A scope that has sending domains can only send from a verified one; a scope with none
  can send from anywhere.
- A publish counts one delivery per active endpoint of the scope that subscribes to the
  type. Deliveries stay `pending` until `markWebhookDelivery()`. Nothing is sent to an
  endpoint, and `replay()` counts the stored events in the window.
- An endpoint URL and a push endpoint must start with `https://`. Whether the host is
  public, or a push service Relay knows, is not checked.
- A ping answers `PingResult('success', 200, 1)` unless `pingAnswers()` says otherwise.
- An erasure removes the stored events published with the subject in that scope and
  their deliveries, and counts those; the fake keeps no attempts to count. What
  `assertPublished()` saw stays seen.
- A source's URL is a made-up `https://relay.test/fake/in/…`. A name is unique per
  scope (a second one answers 422); the real service's answer for that is not described.
  No provider call is ever taken and nothing is forwarded.
- A domain's reputation counts the recipients of the scope's stored mail from that
  domain that are `sent`, `bounced` or `complained`, over all time, with a window of 7.
  Set them with `markMail()` and `markRecipient()`.
- A push to a subscription the scope does not have answers 404; a payload over 3000
  bytes 422. Messages stay `queued` until `markPush()`.
- A mail with every recipient suppressed is `blocked`, otherwise `queued`; it is never
  `quarantined` and never becomes `sent` without `markMail()`. Releasing or discarding
  a mail that is not `quarantined` answers 409 `mail_not_held`; a discard keeps the
  recorded `OutgoingMail`, since the fake has no content to remove. Mail without content or
  recipients, with more than 50 recipients or 20 attachments, or with a header outside
  the allowed set answers 422. Size is not checked.
- Verifying a domain or a domain route finds its DNS record unless `dnsPublished(false)`.
  A subdomain route is active at once; a prefix route stays pending until
  `activateInboundRoute()`.
- The same content judged twice for the same scope gives the same judgment, with
  `created` false the second time.
- Ids are `…_fake_N`. Secrets are `fake_secret_N`. The push key is a fixed string per scope.

The fake does **not** enforce abilities, does not refuse a scope key naming another
scope, and does not refuse calls for a suspended scope or a paused sender. Use
`failWith()` to test how your code handles those answers.
