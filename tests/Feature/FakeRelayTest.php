<?php

declare(strict_types=1);

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\ApiKey;
use Cbox\Relay\Client\Data\Delivery;
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Data\Environment;
use Cbox\Relay\Client\Data\Identity;
use Cbox\Relay\Client\Data\Organization;
use Cbox\Relay\Client\Exceptions\RelayConflict;
use Cbox\Relay\Client\Exceptions\RelayInvalid;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Exceptions\RelayUnavailable;
use Cbox\Relay\Client\Testing\FakeRelay;
use Cbox\Relay\Client\Testing\Operation;
use Cbox\Relay\Client\Testing\RecordedCall;
use PHPUnit\Framework\AssertionFailedError;

it('is a transport', function (): void {
    expect(new FakeRelay)->toBeInstanceOf(RelayTransport::class);
});

it('creates a project once and answers the same project after', function (): void {
    $this->travelTo('2026-11-12 10:00:00');
    $relay = new FakeRelay;

    $first = $relay->putProject('ws_1', 'Acme', ['region' => 'eu']);
    $this->travel(1)->hours();
    $second = $relay->putProject('ws_1');

    expect($first->created)->toBeTrue()
        ->and($first->id)->toStartWith('prj_')
        ->and($first->name)->toBe('Acme')
        ->and($first->settings)->toBe(['region' => 'eu'])
        ->and($second->created)->toBeFalse()
        ->and($second->id)->toBe($first->id)
        ->and($second->name)->toBe('Acme')
        ->and($second->settings)->toBe(['region' => 'eu'])
        ->and($second->createdAt)->toEqual($first->createdAt)
        ->and($relay->projects())->toHaveCount(1);
});

it('updates the name and the settings an existing project is put with', function (): void {
    $relay = new FakeRelay;
    $relay->putProject('ws_1', 'Acme', ['region' => 'eu']);

    $renamed = $relay->putProject('ws_1', 'Acme Inc');
    $moved = $relay->putProject('ws_1', settings: []);

    expect($renamed->name)->toBe('Acme Inc')
        ->and($renamed->settings)->toBe(['region' => 'eu'])
        ->and($moved->name)->toBe('Acme Inc')
        ->and($moved->settings)->toBe([]);
});

it('names a project after its external ref when no name is given', function (): void {
    expect((new FakeRelay)->putProject('ws_1')->name)->toBe('ws_1');
});

it('finds a project by external ref or by id, and null otherwise', function (): void {
    $relay = new FakeRelay;
    $project = $relay->putProject('ws_1', 'Acme');

    expect($relay->findProject('ws_1')?->id)->toBe($project->id)
        ->and($relay->findProject($project->id)?->externalRef)->toBe('ws_1')
        ->and($relay->findProject('ws_1')?->created)->toBeFalse()
        ->and($relay->findProject('nope'))->toBeNull();
});

it('pages the projects with a cursor', function (): void {
    $relay = new FakeRelay;

    foreach (['a', 'b', 'c', 'd', 'e'] as $ref) {
        $relay->seedProject($ref);
    }

    $first = $relay->listProjects(2);
    $second = $relay->listProjects(2, $first->nextCursor);
    $third = $relay->listProjects(2, $second->nextCursor);

    $refs = fn ($page): array => array_map(fn ($project): string => $project->externalRef, $page->items);

    expect($refs($first))->toBe(['a', 'b'])
        ->and($refs($second))->toBe(['c', 'd'])
        ->and($refs($third))->toBe(['e'])
        ->and($first->hasMore())->toBeTrue()
        ->and($third->nextCursor)->toBeNull()
        ->and($relay->listProjects()->items)->toHaveCount(5);
});

it('refuses a limit below one and a cursor it did not hand out', function (): void {
    $relay = new FakeRelay;

    expect(fn () => $relay->listProjects(0))->toThrow(RelayInvalid::class)
        ->and(fn () => $relay->listDeliveries(cursor: 'c1'))->toThrow(RelayInvalid::class);

    try {
        $relay->listProjects(0);
    } catch (RelayInvalid $e) {
        expect($e->errorsFor('limit'))->toHaveCount(1)->and($e->status())->toBe(422);
    }
});

it('has an empty delivery log until one is seeded', function (): void {
    $page = (new FakeRelay)->listDeliveries();

    expect($page->isEmpty())->toBeTrue()->and($page->nextCursor)->toBeNull();
});

it('filters the seeded deliveries, newest first', function (): void {
    $relay = new FakeRelay;

    $old = $relay->seedDeliveryFor('ws_1', 'failed', occurredAt: new DateTimeImmutable('2026-11-01T10:00:00Z'), responseCode: 500, error: 'HTTP 500');
    $new = $relay->seedDeliveryFor('ws_1', 'delivered', occurredAt: new DateTimeImmutable('2026-11-10T10:00:00Z'), responseCode: 200, durationMs: 12);
    $mail = $relay->seedDeliveryFor('ws_2', 'delivered', channel: 'mail', target: 'ada@example.com', occurredAt: new DateTimeImmutable('2026-11-05T10:00:00Z'));

    $ids = fn ($page): array => array_map(fn (Delivery $delivery): string => $delivery->id, $page->items);

    expect($ids($relay->listDeliveries()))->toBe([$new->id, $mail->id, $old->id])
        ->and($ids($relay->listDeliveries(new DeliveryFilter(channel: 'mail'))))->toBe([$mail->id])
        ->and($ids($relay->listDeliveries(new DeliveryFilter(status: 'delivered'))))->toBe([$new->id, $mail->id])
        ->and($ids($relay->listDeliveries(new DeliveryFilter(from: new DateTimeImmutable('2026-11-05T10:00:00Z')))))->toBe([$new->id, $mail->id])
        ->and($ids($relay->listDeliveries(new DeliveryFilter(to: new DateTimeImmutable('2026-11-05T09:59:59Z')))))->toBe([$old->id])
        ->and($ids($relay->listDeliveries(project: 'ws_1')))->toBe([$new->id, $old->id])
        ->and($ids($relay->listDeliveries(limit: 1)))->toBe([$new->id])
        ->and($relay->listDeliveries(limit: 1)->hasMore())->toBeTrue()
        ->and($old->projectId)->toBe($relay->project('ws_1')?->id)
        ->and($old->error)->toBe('HTTP 500');
});

it('keeps deliveries seeded as they are', function (): void {
    $relay = new FakeRelay;
    $delivery = new Delivery('dlv_1', 'webhook', 'event', 'evt_1', 'https://example.test', 'delivered', new DateTimeImmutable('2026-11-10T10:00:00Z'));

    expect($relay->seedDeliveries($delivery)->listDeliveries()->items)->toBe([$delivery]);
});

it('answers whoami as a test organization key, or as told', function (): void {
    $relay = new FakeRelay;

    expect($relay->whoami()->environment)->toBe(Environment::Test)
        ->and($relay->whoami()->project)->toBeNull()
        ->and($relay->whoami()->can('projects:manage'))->toBeTrue();

    $identity = new Identity(new Organization('org_1', 'Cbox'), Environment::Live, new ApiKey('key_1', 'Billing', 'rlk_live_ab12', ['deliveries:read']));

    expect($relay->actingAs($identity)->whoami())->toBe($identity);
});

it('names the project a call acts for in whoami', function (): void {
    $relay = new FakeRelay;
    $project = $relay->seedProject('ws_1', 'Acme');

    $identity = $relay->whoami('ws_1');

    expect($identity->project?->id)->toBe($project->id)
        ->and($identity->project?->externalRef)->toBe('ws_1')
        ->and($identity->project?->name)->toBe('Acme')
        ->and($relay->whoami($project->id)->project?->externalRef)->toBe('ws_1');
});

it('answers 404 when a call acts for a project that is not there', function (): void {
    $relay = new FakeRelay;

    expect(fn () => $relay->whoami('nope'))->toThrow(RelayNotFound::class)
        ->and(fn () => $relay->listDeliveries(project: 'nope'))->toThrow(RelayNotFound::class);
});

it('replays an idempotency key and refuses it for a different request', function (): void {
    $relay = new FakeRelay;

    $relay->putProject('ws_1', 'Acme', idempotencyKey: 'k1');
    $again = $relay->putProject('ws_1', 'Acme', idempotencyKey: 'k1');

    expect($again->created)->toBeFalse()
        ->and(fn () => $relay->putProject('ws_2', 'Globex', idempotencyKey: 'k1'))->toThrow(RelayConflict::class)
        ->and($relay->project('ws_2'))->toBeNull();
});

it('records every call', function (): void {
    $relay = new FakeRelay;
    $relay->seedProject('ws_1');

    $relay->whoami();
    $relay->putProject('ws_2', 'Globex', ['region' => 'eu'], 'k1', 'ws_1');
    $relay->findProject('ws_2');
    $relay->listProjects(10, null);
    $relay->listDeliveries(new DeliveryFilter(channel: 'webhook'), 5, null, 'ws_1');

    expect(array_map(fn (RecordedCall $call): Operation => $call->operation, $relay->calls()))
        ->toBe([Operation::Whoami, Operation::PutProject, Operation::FindProject, Operation::ListProjects, Operation::ListDeliveries]);

    $put = $relay->calls(Operation::PutProject)[0];

    expect($put->ref)->toBe('ws_2')
        ->and($put->name)->toBe('Globex')
        ->and($put->settings)->toBe(['region' => 'eu'])
        ->and($put->idempotencyKey)->toBe('k1')
        ->and($put->actingProject)->toBe('ws_1')
        ->and($relay->calls(Operation::ListDeliveries)[0]->filter?->channel)->toBe('webhook')
        ->and($relay->calls(Operation::ListDeliveries)[0]->limit)->toBe(5);
});

it('asserts on what was put', function (): void {
    $relay = new FakeRelay;
    $relay->putProject('ws_1', 'Acme');
    $relay->putProject('ws_1', 'Acme');

    $relay->assertProjectPut('ws_1');
    $relay->assertProjectPut('ws_1', fn (RecordedCall $call): bool => $call->name === 'Acme');
    $relay->assertProjectPutTimes('ws_1', 2);
    $relay->assertProjectNotPut('ws_2');

    expect(fn () => $relay->assertProjectPut('ws_2'))->toThrow(AssertionFailedError::class, "The project 'ws_2' was not put")
        ->and(fn () => $relay->assertProjectPut('ws_1', fn (RecordedCall $call): bool => $call->name === 'Globex'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $relay->assertProjectPutTimes('ws_1', 1))->toThrow(AssertionFailedError::class)
        ->and(fn () => $relay->assertProjectNotPut('ws_1'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $relay->assertNoProjectPut())->toThrow(AssertionFailedError::class)
        ->and(fn () => $relay->assertNothingSent())->toThrow(AssertionFailedError::class);
});

it('asserts on reads and on the project acted for', function (): void {
    $relay = new FakeRelay;
    $relay->seedProject('ws_1');

    $relay->assertNothingSent();
    $relay->assertNoProjectPut();

    expect(fn () => $relay->assertDeliveriesRead())->toThrow(AssertionFailedError::class)
        ->and(fn () => $relay->assertActedForProject('ws_1'))->toThrow(AssertionFailedError::class);

    $relay->listDeliveries(new DeliveryFilter(status: 'failed'), project: 'ws_1');

    $relay->assertDeliveriesRead();
    $relay->assertDeliveriesRead(fn (RecordedCall $call): bool => $call->filter?->status === 'failed');
    $relay->assertActedForProject('ws_1');
    $relay->assertCalledTimes(Operation::ListDeliveries, 1);
    $relay->assertNoProjectPut();

    expect(fn () => $relay->assertDeliveriesRead(fn (RecordedCall $call): bool => $call->filter?->status === 'delivered'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $relay->assertCalledTimes(Operation::Whoami, 1))->toThrow(AssertionFailedError::class);
});

it('fails every call when told to, and recovers', function (): void {
    $relay = (new FakeRelay)->failWith(new RelayUnavailable);

    expect(fn () => $relay->whoami())->toThrow(RelayUnavailable::class)
        ->and(fn () => $relay->putProject('ws_1'))->toThrow(RelayUnavailable::class)
        ->and($relay->project('ws_1'))->toBeNull()
        ->and($relay->calls())->toHaveCount(2);

    expect($relay->recover()->putProject('ws_1')->created)->toBeTrue();
});
