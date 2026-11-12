<?php

declare(strict_types=1);

use Cbox\Relay\Client\Data\Delivery;
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Data\Identity;
use Cbox\Relay\Client\Data\Page;
use Cbox\Relay\Client\Data\Problem;
use Cbox\Relay\Client\Data\Scope;
use Cbox\Relay\Client\Exceptions\RelayException;
use Cbox\Relay\Client\Exceptions\RelayInvalid;
use Cbox\Relay\Client\Exceptions\RelayRateLimited;

it('round-trips an identity', function (): void {
    $body = [
        'organization' => ['id' => 'org_1', 'name' => 'Cbox'],
        'environment' => 'live',
        'scope' => ['id' => 'scp_1', 'external_ref' => 'ws_1', 'name' => 'Acme'],
        'key' => ['id' => 'key_1', 'name' => 'Billing', 'prefix' => 'rlk_live_ab12', 'abilities' => ['scopes:manage']],
    ];

    expect(Identity::fromArray($body)->toArray())->toBe($body);
});

it('round-trips a scope', function (): void {
    $body = ['id' => 'scp_1', 'external_ref' => 'ws_1', 'name' => 'Acme', 'settings' => ['a' => ['b' => 1]], 'suspended_at' => '2026-11-12T11:00:00+00:00', 'created_at' => '2026-11-12T10:00:00+00:00'];

    $scope = Scope::fromArray($body);

    expect($scope->toArray())->toBe($body)
        ->and($scope->is('ws_1'))->toBeTrue()
        ->and($scope->is('scp_1'))->toBeTrue()
        ->and($scope->is('ws_2'))->toBeFalse();
});

it('round-trips a delivery, with its nullable parts', function (): void {
    $body = [
        'id' => 'dlv_1', 'scope_id' => null, 'channel' => 'webhook', 'subject_type' => 'event', 'subject_id' => 'evt_1',
        'target' => 'https://example.test', 'status' => 'pending', 'attempt' => 1, 'response_code' => null, 'error' => null,
        'duration_ms' => null, 'occurred_at' => '2026-11-12T10:00:00+00:00',
    ];

    expect(Delivery::fromArray($body)->toArray())->toBe($body);
});

it('builds a filter query without what is not set', function (): void {
    expect((new DeliveryFilter)->toQuery())->toBe([])
        ->and((new DeliveryFilter(status: 'failed'))->toQuery())->toBe(['status' => 'failed'])
        ->and((new DeliveryFilter(from: new DateTime('2026-11-12 12:00:00', new DateTimeZone('Europe/Copenhagen'))))->toQuery())->toBe(['from' => '2026-11-12T11:00:00Z']);
});

it('has a meaningful empty page', function (): void {
    $page = new Page;

    expect($page->items)->toBe([])
        ->and($page->isEmpty())->toBeTrue()
        ->and($page->hasMore())->toBeFalse()
        ->and(count($page))->toBe(0)
        ->and(iterator_to_array(new Page(['a', 'b'], 'c')))->toBe(['a', 'b']);
});

it('reads a problem leniently and serializes it back', function (): void {
    $problem = Problem::fromArray(['type' => 'https://docs.cboxrelay.com/problems/validation', 'title' => 'Invalid', 'status' => 422, 'detail' => 'No.', 'errors' => ['name' => ['Too long.', 7], 'ref' => 'Bad.']], 422);

    expect($problem->errors)->toBe(['name' => ['Too long.'], 'ref' => ['Bad.']])
        ->and($problem->toArray())->toBe(['type' => 'https://docs.cboxrelay.com/problems/validation', 'title' => 'Invalid', 'status' => 422, 'detail' => 'No.', 'errors' => ['name' => ['Too long.'], 'ref' => ['Bad.']]])
        ->and(Problem::fromArray([], 500)->toArray())->toBe(['type' => 'about:blank', 'title' => '', 'status' => 500]);
});

it('builds every exception without arguments', function (): void {
    expect((new RelayException)->status())->toBeNull()
        ->and((new RelayInvalid)->errors)->toBe([])
        ->and((new RelayRateLimited(retryAfter: 5))->retryAfter)->toBe(5)
        ->and(new RelayRateLimited)->toBeInstanceOf(RelayException::class);
});
