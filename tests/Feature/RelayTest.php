<?php

declare(strict_types=1);

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Relay as RelayManager;
use Cbox\Relay\Client\Scopes;
use Cbox\Relay\Client\Testing\FakeRelay;
use Cbox\Relay\Client\Testing\Operation;
use Cbox\Relay\Client\Testing\RecordedCall;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

it('swaps the fake in for the facade, the manager and the contract', function (): void {
    $fake = Relay::fake();

    expect($fake)->toBeInstanceOf(FakeRelay::class)
        ->and(app(RelayTransport::class))->toBe($fake)
        ->and(app(RelayManager::class)->transport())->toBe($fake)
        ->and(Relay::transport())->toBe($fake)
        ->and(Relay::getFacadeRoot())->toBeInstanceOf(RelayManager::class);
});

it('swaps the fake in after the facade was already used', function (): void {
    Http::fake(['relay.test/v1/scopes' => Http::response(['data' => [], 'next_cursor' => null])]);
    Relay::scopes()->list();

    $fake = Relay::fake();
    Relay::scopes()->put('ws_1');

    Http::assertSentCount(1);
    $fake->assertScopePut('ws_1');
});

it('takes a fake that was prepared beforehand', function (): void {
    $prepared = new FakeRelay;
    $prepared->seedScope('ws_1', 'Acme');

    expect(Relay::fake($prepared))->toBe($prepared)
        ->and(Relay::scopes()->find('ws_1')?->name)->toBe('Acme');
});

it('fakes through the trait', function (): void {
    $fake = $this->fakeRelay();

    Relay::whoami();

    $fake->assertCalledTimes(Operation::Whoami, 1);
});

it('reads whoami', function (): void {
    $this->fakeRelay();

    expect(Relay::whoami()->organization->id)->toBe('org_fake');
});

it('puts, finds and lists scopes', function (): void {
    $fake = $this->fakeRelay();

    $created = Relay::scopes()->put('ws_1', name: 'Acme', settings: ['region' => 'eu']);
    $again = Relay::scopes()->put('ws_1', name: 'Acme', settings: ['region' => 'eu']);
    Relay::scopes()->put('ws_2', idempotencyKey: 'k2');

    expect(Relay::scopes())->toBeInstanceOf(Scopes::class)
        ->and($created->created)->toBeTrue()
        ->and($again->created)->toBeFalse()
        ->and($again->id)->toBe($created->id)
        ->and(Relay::scopes()->find('ws_1')?->settings)->toBe(['region' => 'eu'])
        ->and(Relay::scopes()->find($created->id)?->externalRef)->toBe('ws_1')
        ->and(Relay::scopes()->find('nope'))->toBeNull()
        ->and(Relay::scopes()->list()->items)->toHaveCount(2);

    $page = Relay::scopes()->list(limit: 1);

    expect($page->items[0]->externalRef)->toBe('ws_1')
        ->and(Relay::scopes()->list(limit: 1, cursor: $page->nextCursor)->items[0]->externalRef)->toBe('ws_2');

    $fake->assertScopePutTimes('ws_1', 2);
    $fake->assertScopePut('ws_2', fn (RecordedCall $call): bool => $call->idempotencyKey === 'k2' && $call->actingScope === null);
});

it('reads the delivery log with a filter, a limit and a cursor', function (): void {
    $fake = $this->fakeRelay();
    $fake->seedDeliveryFor('ws_1', 'delivered', occurredAt: new DateTimeImmutable('2026-11-10T10:00:00Z'));
    $fake->seedDeliveryFor('ws_1', 'failed', occurredAt: new DateTimeImmutable('2026-11-09T10:00:00Z'));
    $fake->seedDeliveryFor('ws_1', 'failed', occurredAt: new DateTimeImmutable('2026-11-08T10:00:00Z'));

    $page = Relay::deliveries(new DeliveryFilter(status: 'failed'), limit: 1);
    $next = Relay::deliveries(new DeliveryFilter(status: 'failed'), limit: 1, cursor: $page->nextCursor);

    expect(Relay::deliveries())->toHaveCount(3)
        ->and($page->items[0]->occurredAt->format('d'))->toBe('09')
        ->and($next->items[0]->occurredAt->format('d'))->toBe('08')
        ->and($next->hasMore())->toBeFalse();

    $fake->assertDeliveriesRead(fn (RecordedCall $call): bool => $call->filter?->status === 'failed' && $call->limit === 1 && $call->cursor !== null);
});

it('acts for a scope on every call of the client forScope returns', function (): void {
    $fake = $this->fakeRelay();
    $fake->seedDeliveryFor('ws_1', 'delivered');
    $fake->seedDeliveryFor('ws_2', 'delivered');

    $scoped = Relay::forScope('ws_1');

    expect($scoped)->toBeInstanceOf(RelayManager::class)
        ->and($scoped->scope())->toBe('ws_1')
        ->and($scoped->whoami()->scope?->externalRef)->toBe('ws_1')
        ->and($scoped->deliveries())->toHaveCount(1)
        ->and($scoped->scopes()->find('ws_2')?->externalRef)->toBe('ws_2')
        ->and($scoped->scopes()->list()->items)->toHaveCount(2)
        ->and(Relay::scope())->toBeNull()
        ->and(Relay::deliveries())->toHaveCount(2);

    $scoped->scopes()->put('ws_3');

    $fake->assertActedForScope('ws_1');
    $fake->assertScopePut('ws_3', fn (RecordedCall $call): bool => $call->actingScope === 'ws_1');

    expect(array_map(fn (RecordedCall $call): ?string => $call->actingScope, $fake->calls()))
        ->toBe(['ws_1', 'ws_1', 'ws_1', 'ws_1', null, 'ws_1']);
});

it('acts for the configured default scope until told otherwise', function (): void {
    config(['relay.scope' => 'ws_1']);

    $fake = $this->fakeRelay();
    $fake->seedScope('ws_1');
    $fake->seedScope('ws_2');

    expect(Relay::scope())->toBe('ws_1')
        ->and(Relay::whoami()->scope?->externalRef)->toBe('ws_1')
        ->and(Relay::forScope('ws_2')->whoami()->scope?->externalRef)->toBe('ws_2')
        ->and(Relay::withoutScope()->whoami()->scope)->toBeNull()
        ->and(Relay::withoutScope()->scope())->toBeNull();
});

it('treats an empty scope as no scope', function (): void {
    config(['relay.scope' => '  ']);
    $this->fakeRelay();

    expect(Relay::scope())->toBeNull()
        ->and(Relay::forScope('')->scope())->toBeNull()
        ->and((new RelayManager(new FakeRelay, ''))->scope())->toBeNull();
});

it('sends the scope as Relay-Scope over HTTP', function (): void {
    Http::fake(['relay.test/v1/deliveries' => Http::response(['data' => [], 'next_cursor' => null])]);

    Relay::forScope('ws_1')->deliveries();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Relay-Scope', 'ws_1'));
});

it('publishes its config', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'relay-config'])->assertSuccessful();

    expect(file_exists(config_path('relay.php')))->toBeTrue();

    unlink(config_path('relay.php'));
});

it('ships the documented defaults', function (): void {
    $config = require __DIR__.'/../../config/relay.php';

    expect($config)->toHaveKeys(['url', 'key', 'timeout', 'connect_timeout', 'scope'])
        ->and($config['timeout'])->toBe(5.0)
        ->and($config['connect_timeout'])->toBe(2.0);
});
