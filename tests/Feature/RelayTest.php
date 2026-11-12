<?php

declare(strict_types=1);

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Projects;
use Cbox\Relay\Client\Relay as RelayManager;
use Cbox\Relay\Client\RelayConfig;
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
    Http::fake(['relay.test/v1/projects' => Http::response(['data' => [], 'next_cursor' => null])]);
    Relay::projects()->list();

    $fake = Relay::fake();
    Relay::projects()->put('ws_1');

    Http::assertSentCount(1);
    $fake->assertProjectPut('ws_1');
});

it('takes a fake that was prepared beforehand', function (): void {
    $prepared = new FakeRelay;
    $prepared->seedProject('ws_1', 'Acme');

    expect(Relay::fake($prepared))->toBe($prepared)
        ->and(Relay::projects()->find('ws_1')?->name)->toBe('Acme');
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

it('puts, finds and lists projects', function (): void {
    $fake = $this->fakeRelay();

    $created = Relay::projects()->put('ws_1', name: 'Acme', settings: ['region' => 'eu']);
    $again = Relay::projects()->put('ws_1', name: 'Acme', settings: ['region' => 'eu']);
    Relay::projects()->put('ws_2', idempotencyKey: 'k2');

    expect(Relay::projects())->toBeInstanceOf(Projects::class)
        ->and($created->created)->toBeTrue()
        ->and($again->created)->toBeFalse()
        ->and($again->id)->toBe($created->id)
        ->and(Relay::projects()->find('ws_1')?->settings)->toBe(['region' => 'eu'])
        ->and(Relay::projects()->find($created->id)?->externalRef)->toBe('ws_1')
        ->and(Relay::projects()->find('nope'))->toBeNull()
        ->and(Relay::projects()->list()->items)->toHaveCount(2);

    $page = Relay::projects()->list(limit: 1);

    expect($page->items[0]->externalRef)->toBe('ws_1')
        ->and(Relay::projects()->list(limit: 1, cursor: $page->nextCursor)->items[0]->externalRef)->toBe('ws_2');

    $fake->assertProjectPutTimes('ws_1', 2);
    $fake->assertProjectPut('ws_2', fn (RecordedCall $call): bool => $call->idempotencyKey === 'k2' && $call->actingProject === null);
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

it('acts for a project on every call of the client forProject returns', function (): void {
    $fake = $this->fakeRelay();
    $fake->seedDeliveryFor('ws_1', 'delivered');
    $fake->seedDeliveryFor('ws_2', 'delivered');

    $scoped = Relay::forProject('ws_1');

    expect($scoped)->toBeInstanceOf(RelayManager::class)
        ->and($scoped->project())->toBe('ws_1')
        ->and($scoped->whoami()->project?->externalRef)->toBe('ws_1')
        ->and($scoped->deliveries())->toHaveCount(1)
        ->and($scoped->projects()->find('ws_2')?->externalRef)->toBe('ws_2')
        ->and($scoped->projects()->list()->items)->toHaveCount(2)
        ->and(Relay::project())->toBeNull()
        ->and(Relay::deliveries())->toHaveCount(2);

    $scoped->projects()->put('ws_3');

    $fake->assertActedForProject('ws_1');
    $fake->assertProjectPut('ws_3', fn (RecordedCall $call): bool => $call->actingProject === 'ws_1');

    expect(array_map(fn (RecordedCall $call): ?string => $call->actingProject, $fake->calls()))
        ->toBe(['ws_1', 'ws_1', 'ws_1', 'ws_1', null, 'ws_1']);
});

it('acts for the configured default project until told otherwise', function (): void {
    config(['relay.project' => 'ws_1']);

    $fake = $this->fakeRelay();
    $fake->seedProject('ws_1');
    $fake->seedProject('ws_2');

    expect(Relay::project())->toBe('ws_1')
        ->and(Relay::whoami()->project?->externalRef)->toBe('ws_1')
        ->and(Relay::forProject('ws_2')->whoami()->project?->externalRef)->toBe('ws_2')
        ->and(Relay::withoutProject()->whoami()->project)->toBeNull()
        ->and(Relay::withoutProject()->project())->toBeNull();
});

it('treats an empty project as no project', function (): void {
    config(['relay.project' => '  ']);
    $this->fakeRelay();

    expect(Relay::project())->toBeNull()
        ->and(Relay::forProject('')->project())->toBeNull()
        ->and((new RelayManager(new FakeRelay, ''))->project())->toBeNull();
});

it('sends the project as Relay-Project over HTTP', function (): void {
    Http::fake(['relay.test/v1/deliveries' => Http::response(['data' => [], 'next_cursor' => null])]);

    Relay::forProject('ws_1')->deliveries();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Relay-Project', 'ws_1'));
});

it('publishes its config', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'relay-config'])->assertSuccessful();

    expect(file_exists(config_path('relay.php')))->toBeTrue();

    unlink(config_path('relay.php'));
});

it('ships the documented defaults', function (): void {
    $config = require __DIR__.'/../../config/relay.php';

    expect($config)->toHaveKeys(['url', 'key', 'timeout', 'connect_timeout', 'project', 'project_key', 'publish', 'webhook', 'store', 'connections'])
        ->and($config['timeout'])->toBe(5.0)
        ->and($config['connect_timeout'])->toBe(2.0)
        ->and($config['project_key'])->toBeFalse()
        ->and($config['publish'])->toBe(['sync' => false, 'connection' => null, 'queue' => null, 'tries' => 5, 'backoff' => [10, 30, 120, 600]])
        ->and($config['webhook'])->toBe(['secret' => null, 'previous_secret' => null, 'tolerance' => 300, 'route' => null, 'cache' => null, 'dedupe' => 172800])
        // Off unless asked for: no webhook route, no local store, one connection.
        ->and($config['store'])->toBe(['enabled' => false, 'connection' => null, 'subject' => 'hash'])
        ->and($config['connections'])->toBe([]);
});

it('reads the publish and webhook settings, falling back when they are unusable', function (): void {
    $config = app(RelayConfig::class);

    expect($config->publishSync())->toBeFalse()
        ->and($config->projectKey())->toBeFalse()
        ->and($config->publishConnection())->toBeNull()
        ->and($config->publishQueue())->toBeNull()
        ->and($config->publishTries())->toBe(5)
        ->and($config->publishBackoff())->toBe([10, 30, 120, 600])
        ->and($config->webhookSecrets())->toBe([])
        ->and($config->webhookTolerance())->toBe(300);

    config()->set('relay.publish', ['sync' => 'true', 'connection' => ' sqs ', 'queue' => 'relay', 'tries' => 0, 'backoff' => '5, 15,x']);
    config()->set('relay.webhook', ['secret' => 'new', 'previous_secret' => 'old', 'tolerance' => -1]);
    config()->set('relay.project_key', '1');

    expect($config->publishSync())->toBeTrue()
        ->and($config->projectKey())->toBeTrue()
        ->and($config->publishConnection())->toBe('sqs')
        ->and($config->publishQueue())->toBe('relay')
        ->and($config->publishTries())->toBe(5)
        ->and($config->publishBackoff())->toBe([5, 15])
        ->and($config->webhookSecrets())->toBe(['new', 'old'])
        ->and($config->webhookTolerance())->toBe(300);
});

it('reads a project that has no name', function (): void {
    Http::fake(['relay.test/v1/projects/ws_1' => Http::response(['id' => 'prj_1', 'external_ref' => 'ws_1', 'name' => null, 'settings' => [], 'suspended_at' => null, 'created_at' => '2026-11-12T10:00:00Z'])]);

    expect(Relay::projects()->find('ws_1')?->name)->toBeNull();
});
