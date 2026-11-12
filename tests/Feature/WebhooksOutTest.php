<?php

declare(strict_types=1);

use Cbox\Relay\Client\Data\Endpoint;
use Cbox\Relay\Client\Data\Envelope;
use Cbox\Relay\Client\Data\EventType;
use Cbox\Relay\Client\Data\NewEvent;
use Cbox\Relay\Client\Data\PingResult;
use Cbox\Relay\Client\Exceptions\RelayConflict;
use Cbox\Relay\Client\Exceptions\RelayInvalid;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Exceptions\RelayScopeRequired;
use Cbox\Relay\Client\Exceptions\RelayUnavailable;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Jobs\PublishEvent;
use Cbox\Relay\Client\Testing\Operation;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    $this->relay = Relay::fake();
    $this->relay->seedScope('ws_1', 'Acme');
});

it('declares a scope\'s event types as exactly the ones given', function (): void {
    $declared = Relay::forScope('ws_1')->eventTypes()->declare(['order.paid', new EventType('order.refunded', name: 'Order refunded')]);

    expect($declared)->toHaveCount(2)
        ->and($declared[1]->name)->toBe('Order refunded')
        ->and(Relay::forScope('ws_1')->eventTypes()->all())->toEqual($declared);

    Relay::forScope('ws_1')->eventTypes()->declare(['order.paid']);

    $this->relay->assertEventTypesDeclared(['order.paid'], 'ws_1');
});

it('refuses to declare one of Relay\'s own event names', function (): void {
    Relay::forScope('ws_1')->eventTypes()->declare(['mail.received']);
})->throws(RelayInvalid::class);

it('registers an endpoint and gives the secret only then', function (): void {
    $endpoints = Relay::forScope('ws_1')->endpoints();

    $endpoint = $endpoints->register('https://customer.example/hooks', ['order.*'], description: 'Orders', envelope: Envelope::CloudEvents);

    expect($endpoint->secret)->not->toBeNull()
        ->and($endpoint->envelope)->toBe('cloudevents')
        ->and($endpoint->signatureScheme)->toBe('cbox')
        ->and($endpoint->active())->toBeTrue()
        ->and($endpoints->find($endpoint->id)?->secret)->toBeNull()
        ->and($endpoints->list())->toHaveCount(1)
        ->and($endpoints->find('ep_missing'))->toBeNull();

    $this->relay->assertEndpointRegistered('https://customer.example/hooks', fn (Endpoint $e): bool => $e->description === 'Orders');
});

it('answers a repeated registration with the same idempotency key once', function (): void {
    $endpoints = Relay::forScope('ws_1')->endpoints();

    $first = $endpoints->register('https://customer.example/hooks', ['*'], idempotencyKey: 'reg-1');
    $again = $endpoints->register('https://customer.example/hooks', ['*'], idempotencyKey: 'reg-1');

    expect($again->id)->toBe($first->id)->and($endpoints->list())->toHaveCount(1);

    expect(fn () => $endpoints->register('https://other.example/hooks', ['*'], idempotencyKey: 'reg-1'))->toThrow(RelayConflict::class);
});

it('refuses an endpoint that is not https', function (): void {
    Relay::forScope('ws_1')->endpoints()->register('http://customer.example/hooks', ['*']);
})->throws(RelayInvalid::class);

it('updates, pauses, resumes, rotates, pings and deletes an endpoint', function (): void {
    $endpoints = Relay::forScope('ws_1')->endpoints();
    $endpoint = $endpoints->register('https://customer.example/hooks', ['order.*'], description: 'Orders');

    $updated = $endpoints->update($endpoint->id, eventTypes: ['*'], clearDescription: true);
    expect($updated->eventTypes)->toBe(['*'])->and($updated->description)->toBeNull()->and($updated->url)->toBe('https://customer.example/hooks');

    expect($endpoints->pause($endpoint->id)->status)->toBe('paused')
        ->and($endpoints->resume($endpoint->id)->status)->toBe('active');

    $rotated = $endpoints->rotateSecret($endpoint->id);
    expect($rotated->secret)->not->toBeNull()->not->toBe($endpoint->secret)
        ->and($rotated->previousSecretExpiresAt)->not->toBeNull();

    expect($endpoints->ping($endpoint->id)->responseCode)->toBe(200);
    $this->relay->pingAnswers(new PingResult('failed', 500, 12, 'HTTP 500'));
    expect($endpoints->ping($endpoint->id)->error)->toBe('HTTP 500');

    $endpoints->delete($endpoint->id);
    expect($endpoints->list())->toBe([]);

    expect(fn () => $endpoints->pause($endpoint->id))->toThrow(RelayNotFound::class);
});

it('records a publish on the fake at once instead of queueing a job', function (): void {
    Bus::fake();

    $key = Relay::forScope('ws_1')->publish('order.paid', ['id' => 'o_1'], occurredAt: new DateTimeImmutable('2026-11-12T10:00:00Z'));

    Bus::assertNothingDispatched();

    expect($key)->toStartWith('pub_');

    $this->relay->assertPublished('order.paid');
    $this->relay->assertPublished('order.paid', fn (NewEvent $event, ?string $scope): bool => $event->payload === ['id' => 'o_1']
        && $event->idempotencyKey === $key
        && $event->occurredAt?->format('c') === '2026-11-12T10:00:00+00:00'
        && $scope === 'ws_1');
    $this->relay->assertPublishedTimes('order.paid', 1);
    $this->relay->assertNotPublished('order.refunded');
});

it('publishes the same idempotency key once', function (): void {
    $relay = Relay::forScope('ws_1');
    $relay->endpoints()->register('https://customer.example/hooks', ['order.*']);
    $relay->endpoints()->register('https://other.example/hooks', ['invoice.paid']);

    $first = $relay->publishNow('order.paid', ['id' => 'o_1'], 'order.paid:o_1');
    $again = $relay->publishNow('order.paid', ['id' => 'o_1'], 'order.paid:o_1');

    expect($first->duplicate)->toBeFalse()
        ->and($first->deliveries)->toBe(1)
        ->and($again->duplicate)->toBeTrue()
        ->and($again->id)->toBe($first->id);

    $this->relay->assertPublishedTimes('order.paid', 1);
    $this->relay->assertCalledTimes(Operation::PublishEvent, 2);
});

it('only publishes declared types once a scope has declared some', function (): void {
    $relay = Relay::forScope('ws_1');
    $relay->publishNow('anything.goes');

    $relay->eventTypes()->declare(['order.paid']);
    $relay->publishNow('order.paid');

    expect(fn () => $relay->publishNow('order.refunded'))->toThrow(RelayInvalid::class);
});

it('publishes a batch, all or none', function (): void {
    $relay = Relay::forScope('ws_1');
    $relay->eventTypes()->declare(['order.paid']);

    $published = $relay->publishBatch([new NewEvent('order.paid', ['id' => 'o_1']), new NewEvent('order.paid', ['id' => 'o_2'])]);

    expect($published)->toHaveCount(2)->and($published[0]->id)->not->toBe($published[1]->id);

    expect(fn () => $relay->publishBatch([new NewEvent('order.paid', ['id' => 'o_3']), new NewEvent('order.lost')]))->toThrow(RelayInvalid::class);

    $this->relay->assertPublishedTimes('order.paid', 2);
});

it('reads events, their deliveries, retries one and replays an endpoint', function (): void {
    $this->travelTo('2026-11-12 10:00:00');
    $relay = Relay::forScope('ws_1');
    $endpoint = $relay->endpoints()->register('https://customer.example/hooks', ['order.*']);

    $published = $relay->publishNow('order.paid', ['id' => 'o_1']);
    $relay->publishNow('invoice.sent', ['id' => 'i_1']);

    $page = $relay->events()->list();
    expect($page)->toHaveCount(2)
        ->and($page->items[0]->type)->toBe('invoice.sent')
        ->and($page->items[0]->payload)->toBeNull()
        ->and($relay->events()->list(type: 'order.paid'))->toHaveCount(1);

    $event = $relay->events()->find($published->id);
    expect($event?->payload)->toBe(['id' => 'o_1'])
        ->and($event?->deliveries)->toHaveCount(1)
        ->and($event?->deliveries[0]->sequence)->toBe(1)
        ->and($event?->deliveries[0]->endpointId)->toBe($endpoint->id)
        ->and($relay->events()->find('evt_missing'))->toBeNull();

    $delivery = $event->deliveries[0];
    $this->relay->markWebhookDelivery($delivery->id, 'exhausted', 500, 'HTTP 500');
    expect($relay->events()->find($published->id)?->deliveries[0]->status)->toBe('exhausted');

    expect($relay->events()->retryDelivery($delivery->id)->status)->toBe('pending')
        ->and($relay->events()->find($published->id)?->deliveries[0]->status)->toBe('pending');

    expect(fn () => $relay->events()->retryDelivery('whd_missing'))->toThrow(RelayNotFound::class);

    expect($relay->endpoints()->replay($endpoint->id, new DateTimeImmutable('2026-11-12 09:00:00')))->toBe(1)
        ->and($relay->endpoints()->replay($endpoint->id, new DateTimeImmutable('2026-11-12 11:00:00')))->toBe(0)
        ->and($relay->endpoints()->find($endpoint->id)?->lastSequence)->toBe(2);
});

it('keeps each scope\'s webhooks apart', function (): void {
    $this->relay->seedScope('ws_2');
    Relay::forScope('ws_1')->endpoints()->register('https://customer.example/hooks', ['*']);
    Relay::forScope('ws_1')->publishNow('order.paid');

    expect(Relay::forScope('ws_2')->endpoints()->list())->toBe([])
        ->and(Relay::forScope('ws_2')->events()->list())->toHaveCount(0);
});

it('throws before anything is sent when a scoped call has no scope', function (Closure $call): void {
    expect($call)->toThrow(RelayScopeRequired::class);

    $this->relay->assertNothingSent();
})->with([
    'publish' => [fn () => Relay::publish('order.paid')],
    'publishNow' => [fn () => Relay::publishNow('order.paid')],
    'publishBatch' => [fn () => Relay::publishBatch([new NewEvent('order.paid')])],
    'event types' => [fn () => Relay::eventTypes()->all()],
    'endpoints' => [fn () => Relay::endpoints()->list()],
    'events' => [fn () => Relay::events()->list()],
    'judge' => [fn () => Relay::judge(text: 'Hi')],
    'push key' => [fn () => Relay::pushKey()],
    'push' => [fn () => Relay::push('psb_1', 'Hi')],
    'subscriptions' => [fn () => Relay::pushSubscriptions()->list()],
    'inbound routes' => [fn () => Relay::inboundRoutes()->list()],
    'domains' => [fn () => Relay::domains()->list()],
    'suppressions' => [fn () => Relay::suppressions()->list()],
    'mail' => [fn () => Relay::mail('msg_1')],
    'after withoutScope' => [fn () => Relay::forScope('ws_1')->withoutScope()->endpoints()->list()],
]);

it('lets scoped calls through without a scope when the key is a scope key', function (): void {
    config()->set('relay.scope_key', true);

    Relay::publish('order.paid');
    Relay::endpoints()->list();

    $this->relay->assertPublished('order.paid', fn (NewEvent $event, ?string $scope): bool => $scope === null);
});

it('uses the configured default scope for scoped calls', function (): void {
    config()->set('relay.scope', 'ws_1');

    Relay::publish('order.paid');

    $this->relay->assertPublished('order.paid', fn (NewEvent $event, ?string $scope): bool => $scope === 'ws_1');
    $this->relay->assertActedForScope('ws_1');
});

it('answers 404 for a scope the fake does not know, unless told to accept any', function (): void {
    expect(fn () => Relay::forScope('ws_unknown')->publish('order.paid'))->toThrow(RelayNotFound::class);

    $this->relay->acceptAnyScope();
    Relay::forScope('ws_unknown')->publish('order.paid');

    expect($this->relay->scope('ws_unknown'))->not->toBeNull();
    $this->relay->assertPublishedTimes('order.paid', 1);
});

it('fails its publish assertions when they do not hold', function (): void {
    expect(fn () => $this->relay->assertPublished('order.paid'))->toThrow(AssertionFailedError::class);

    $this->relay->assertNothingPublished();
    Relay::forScope('ws_1')->publish('order.paid', ['id' => 'o_1']);

    expect(fn () => $this->relay->assertNothingPublished())->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->relay->assertNotPublished('order.paid'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->relay->assertPublished('order.paid', fn (NewEvent $event): bool => $event->payload === []))->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->relay->assertPublishedTimes('order.paid', 2))->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->relay->assertEndpointRegistered('https://nowhere.example'))->toThrow(AssertionFailedError::class);
});

it('throws what the fake was told to fail with from a publish', function (): void {
    $this->relay->failWith(new RelayUnavailable);

    expect(fn () => Relay::forScope('ws_1')->publish('order.paid'))->toThrow(RelayUnavailable::class);

    $this->relay->assertNothingPublished();
});

it('generates a fresh idempotency key for every publish that has none', function (): void {
    expect(PublishEvent::newIdempotencyKey())->toMatch('/^pub_[0-9a-f]{32}$/')
        ->and(PublishEvent::newIdempotencyKey())->not->toBe(PublishEvent::newIdempotencyKey());
});
