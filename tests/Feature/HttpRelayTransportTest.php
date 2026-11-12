<?php

declare(strict_types=1);

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Data\Environment;
use Cbox\Relay\Client\Exceptions\RelayConflict;
use Cbox\Relay\Client\Exceptions\RelayException;
use Cbox\Relay\Client\Exceptions\RelayForbidden;
use Cbox\Relay\Client\Exceptions\RelayInvalid;
use Cbox\Relay\Client\Exceptions\RelayMalformedResponse;
use Cbox\Relay\Client\Exceptions\RelayNotConfigured;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Exceptions\RelayRateLimited;
use Cbox\Relay\Client\Exceptions\RelayUnauthorized;
use Cbox\Relay\Client\Exceptions\RelayUnavailable;
use Cbox\Relay\Client\Http\HttpRelayTransport;
use Cbox\Relay\Client\RelayConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const KEY = 'rlk_test_0123456789abcdefghijABCDEFGHIJ0123456789';

function transport(): RelayTransport
{
    return app(RelayTransport::class);
}

/**
 * @return array<string, mixed>
 */
function scopeBody(string $ref = 'ws_1', string $name = 'Acme'): array
{
    return ['id' => 'scp_1', 'external_ref' => $ref, 'name' => $name, 'settings' => [], 'suspended_at' => null, 'created_at' => '2026-11-12T10:00:00+00:00'];
}

/**
 * @param  array<string, mixed>  $extra
 */
function problem(int $status, string $title, ?string $detail = null, array $extra = []): array
{
    return ['type' => 'https://docs.cboxrelay.com/problems/'.$status, 'title' => $title, 'status' => $status, 'detail' => $detail, ...$extra];
}

beforeEach(fn () => Http::preventStrayRequests());

it('is the bound transport', function (): void {
    expect(transport())->toBeInstanceOf(HttpRelayTransport::class);
});

it('reads whoami with the bearer key and no scope header', function (): void {
    Http::fake(['relay.test/v1/whoami' => Http::response([
        'organization' => ['id' => 'org_1', 'name' => 'Cbox'],
        'environment' => 'live',
        'scope' => null,
        'key' => ['id' => 'key_1', 'name' => 'Billing', 'prefix' => 'rlk_live_ab12', 'abilities' => ['scopes:manage', 'deliveries:read']],
    ])]);

    $identity = transport()->whoami();

    expect($identity->organization->id)->toBe('org_1')
        ->and($identity->organization->name)->toBe('Cbox')
        ->and($identity->environment)->toBe(Environment::Live)
        ->and($identity->live())->toBeTrue()
        ->and($identity->scope)->toBeNull()
        ->and($identity->scoped())->toBeFalse()
        ->and($identity->key->id)->toBe('key_1')
        ->and($identity->key->prefix)->toBe('rlk_live_ab12')
        ->and($identity->can('scopes:manage'))->toBeTrue()
        ->and($identity->can('messages:send'))->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://relay.test/v1/whoami'
        && $request->hasHeader('Authorization', 'Bearer '.KEY)
        && $request->hasHeader('Accept', 'application/json')
        && ! $request->hasHeader('Relay-Scope')
        && ! $request->hasHeader('Idempotency-Key'));
});

it('sends Relay-Scope when acting for a scope and maps the scope whoami names', function (): void {
    Http::fake(['relay.test/v1/whoami' => Http::response([
        'organization' => ['id' => 'org_1', 'name' => 'Cbox'],
        'environment' => 'test',
        'scope' => ['id' => 'scp_1', 'external_ref' => 'ws_1', 'name' => 'Acme'],
        'key' => ['id' => 'key_1', 'name' => 'Billing', 'prefix' => 'rlk_test_ab12', 'abilities' => []],
    ])]);

    $identity = transport()->whoami('ws_1');

    expect($identity->scoped())->toBeTrue()
        ->and($identity->scope?->id)->toBe('scp_1')
        ->and($identity->scope?->externalRef)->toBe('ws_1')
        ->and($identity->environment)->toBe(Environment::Test);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Relay-Scope', 'ws_1'));
});

it('puts a scope and knows it was created from the 201', function (): void {
    Http::fake(['relay.test/v1/scopes/ws_1' => Http::response(['id' => 'scp_1', 'external_ref' => 'ws_1', 'name' => 'Acme', 'settings' => ['region' => 'eu'], 'suspended_at' => null, 'created_at' => '2026-11-12T10:00:00+00:00'], 201)]);

    $scope = transport()->putScope('ws_1', 'Acme', ['region' => 'eu'], 'put-ws_1');

    expect($scope->id)->toBe('scp_1')
        ->and($scope->externalRef)->toBe('ws_1')
        ->and($scope->name)->toBe('Acme')
        ->and($scope->settings)->toBe(['region' => 'eu'])
        ->and($scope->suspended())->toBeFalse()
        ->and($scope->createdAt->format(DATE_ATOM))->toBe('2026-11-12T10:00:00+00:00')
        ->and($scope->created)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://relay.test/v1/scopes/ws_1'
        && $request->hasHeader('Content-Type', 'application/json')
        && $request->hasHeader('Idempotency-Key', 'put-ws_1')
        && $request->data() === ['name' => 'Acme', 'settings' => ['region' => 'eu']]);
});

it('knows a put scope existed from the 200', function (): void {
    Http::fake(['relay.test/v1/scopes/ws_1' => Http::response([...scopeBody(), 'suspended_at' => '2026-11-12T11:00:00Z'], 200)]);

    $scope = transport()->putScope('ws_1');

    expect($scope->created)->toBeFalse()
        ->and($scope->suspended())->toBeTrue();
});

it('sends an empty JSON object when a put carries nothing, and empty settings as an object', function (): void {
    Http::fake(['relay.test/v1/scopes/*' => Http::response(scopeBody())]);

    transport()->putScope('ws_1');
    transport()->putScope('ws_1', settings: []);

    $bodies = Http::recorded()->map(fn (array $pair): string => $pair[0]->body())->all();

    expect($bodies)->toBe(['{}', '{"settings":{}}']);
});

it('encodes the external ref in the path', function (): void {
    Http::fake(['relay.test/v1/scopes/*' => Http::response(scopeBody('team/7 a'))]);

    transport()->putScope('team/7 a');
    transport()->findScope('team/7 a');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://relay.test/v1/scopes/team%2F7%20a');
});

it('finds a scope, and answers null for a 404', function (): void {
    Http::fake([
        'relay.test/v1/scopes/ws_1' => Http::response(scopeBody()),
        'relay.test/v1/scopes/nope' => Http::response(problem(404, 'Not Found'), 404, ['Content-Type' => 'application/problem+json']),
    ]);

    expect(transport()->findScope('ws_1')?->id)->toBe('scp_1')
        ->and(transport()->findScope('ws_1')?->created)->toBeFalse()
        ->and(transport()->findScope('nope'))->toBeNull();
});

it('lists scopes with limit and cursor', function (): void {
    Http::fake(['relay.test/v1/scopes*' => Http::response(['data' => [scopeBody('ws_1'), scopeBody('ws_2', 'Globex')], 'next_cursor' => 'c2'])]);

    $page = transport()->listScopes(2, 'c1');

    expect($page)->toHaveCount(2)
        ->and($page->items[1]->externalRef)->toBe('ws_2')
        ->and($page->nextCursor)->toBe('c2')
        ->and($page->hasMore())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://relay.test/v1/scopes?limit=2&cursor=c1');
});

it('lists scopes without a query string when nothing is asked', function (): void {
    Http::fake(['relay.test/v1/scopes' => Http::response(['data' => [], 'next_cursor' => null])]);

    $page = transport()->listScopes();

    expect($page->isEmpty())->toBeTrue()->and($page->hasMore())->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://relay.test/v1/scopes');
});

it('reads the delivery log with the filter as query parameters', function (): void {
    Http::fake(['relay.test/v1/deliveries*' => Http::response(['data' => [[
        'id' => 'dlv_1', 'scope_id' => 'scp_1', 'channel' => 'webhook', 'subject_type' => 'event', 'subject_id' => 'evt_1',
        'target' => 'https://example.test/hooks', 'status' => 'delivered', 'attempt' => 2, 'response_code' => 200, 'error' => null,
        'duration_ms' => 12, 'occurred_at' => '2026-11-12T10:00:00Z',
    ]], 'next_cursor' => null])]);

    $page = transport()->listDeliveries(new DeliveryFilter(
        channel: 'webhook',
        status: 'delivered',
        from: new DateTimeImmutable('2026-11-01T02:00:00+02:00'),
        to: new DateTimeImmutable('2026-11-30T00:00:00Z'),
    ), 10, 'c1', 'ws_1');

    $delivery = $page->items[0];

    expect($delivery->id)->toBe('dlv_1')
        ->and($delivery->scopeId)->toBe('scp_1')
        ->and($delivery->channel)->toBe('webhook')
        ->and($delivery->subjectType)->toBe('event')
        ->and($delivery->subjectId)->toBe('evt_1')
        ->and($delivery->target)->toBe('https://example.test/hooks')
        ->and($delivery->status)->toBe('delivered')
        ->and($delivery->attempt)->toBe(2)
        ->and($delivery->responseCode)->toBe(200)
        ->and($delivery->error)->toBeNull()
        ->and($delivery->durationMs)->toBe(12)
        ->and($delivery->occurredAt->getTimestamp())->toBe(1794477600);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://relay.test/v1/deliveries?')
            && $request->hasHeader('Relay-Scope', 'ws_1')
            && $query === ['channel' => 'webhook', 'status' => 'delivered', 'from' => '2026-11-01T00:00:00Z', 'to' => '2026-11-30T00:00:00Z', 'limit' => '10', 'cursor' => 'c1'];
    });
});

it('reads an empty delivery log', function (): void {
    Http::fake(['relay.test/v1/deliveries' => Http::response(['data' => [], 'next_cursor' => null])]);

    expect(transport()->listDeliveries()->items)->toBe([]);
});

it('maps each problem status to its exception', function (int $status, string $exception): void {
    Http::fake(['relay.test/*' => Http::response(problem($status, 'Nope', 'Because.'), $status, ['Content-Type' => 'application/problem+json'])]);

    try {
        transport()->whoami();
    } catch (RelayException $e) {
        expect($e::class)->toBe($exception)
            ->and($e->status())->toBe($status)
            ->and($e->problem?->title)->toBe('Nope')
            ->and($e->problem?->detail)->toBe('Because.')
            ->and($e->problem?->type)->toBe('https://docs.cboxrelay.com/problems/'.$status)
            ->and($e->getMessage())->toBe("Cbox Relay answered {$status} Nope: Because.")
            ->and($e->getMessage())->not->toContain(KEY);

        return;
    }

    $this->fail('No exception was thrown.');
})->with([
    'bad or revoked key' => [401, RelayUnauthorized::class],
    'missing ability' => [403, RelayForbidden::class],
    'unknown' => [404, RelayNotFound::class],
    'idempotency conflict' => [409, RelayConflict::class],
    'validation' => [422, RelayInvalid::class],
    'rate limited' => [429, RelayRateLimited::class],
    'server error' => [500, RelayUnavailable::class],
    'bad gateway' => [502, RelayUnavailable::class],
    'unavailable' => [503, RelayUnavailable::class],
    'a status nothing names' => [418, RelayException::class],
]);

it('carries the field errors of a validation problem', function (): void {
    Http::fake(['relay.test/*' => Http::response(problem(422, 'Unprocessable Content', 'The name is too long.', ['errors' => ['name' => ['The name is too long.'], 'settings.region' => ['Unknown region.', 'Pick one.']]]), 422)]);

    try {
        transport()->putScope('ws_1', str_repeat('a', 300));
        $this->fail('No exception was thrown.');
    } catch (RelayInvalid $e) {
        expect($e->errors)->toBe(['name' => ['The name is too long.'], 'settings.region' => ['Unknown region.', 'Pick one.']])
            ->and($e->errorsFor('name'))->toBe(['The name is too long.'])
            ->and($e->errorsFor('other'))->toBe([])
            ->and($e->problem?->errors)->toBe($e->errors);
    }
});

it('carries Retry-After of a rate limit', function (string $header, ?int $expected): void {
    Http::fake(['relay.test/*' => Http::response(problem(429, 'Too Many Requests'), 429, $header === '' ? [] : ['Retry-After' => $header])]);

    try {
        transport()->whoami();
        $this->fail('No exception was thrown.');
    } catch (RelayRateLimited $e) {
        expect($e->retryAfter)->toBe($expected);
    }
})->with([
    'seconds' => ['30', 30],
    'absent' => ['', null],
    'unreadable' => ['soon-ish', null],
]);

it('reads Retry-After as an HTTP date', function (): void {
    Http::fake(['relay.test/*' => Http::response(problem(429, 'Too Many Requests'), 429, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 120)])]);

    try {
        transport()->whoami();
        $this->fail('No exception was thrown.');
    } catch (RelayRateLimited $e) {
        expect($e->retryAfter)->toBeGreaterThanOrEqual(118)->toBeLessThanOrEqual(120);
    }
});

it('still maps an error whose body is not a problem document', function (): void {
    Http::fake(['relay.test/*' => Http::response('<html>Bad gateway</html>', 502, ['Content-Type' => 'text/html'])]);

    try {
        transport()->whoami();
        $this->fail('No exception was thrown.');
    } catch (RelayUnavailable $e) {
        expect($e->status())->toBe(502)
            ->and($e->problem?->type)->toBe('about:blank')
            ->and($e->getMessage())->toBe('Cbox Relay answered 502.');
    }
});

it('throws RelayUnavailable when Relay cannot be reached', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    try {
        transport()->whoami();
        $this->fail('No exception was thrown.');
    } catch (RelayUnavailable $e) {
        expect($e->status())->toBeNull()
            ->and($e->problem)->toBeNull()
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class)
            ->and($e->getMessage())->toContain('Operation timed out')
            ->and($e->getMessage())->not->toContain(KEY);
    }
});

it('does not turn a 404 on a list or a put into null', function (): void {
    Http::fake(['relay.test/*' => Http::response(problem(404, 'Not Found'), 404)]);

    expect(fn () => transport()->listScopes())->toThrow(RelayNotFound::class)
        ->and(fn () => transport()->putScope('ws_1'))->toThrow(RelayNotFound::class);
});

it('refuses a success body it cannot read', function (mixed $body): void {
    Http::fake(['relay.test/*' => Http::response($body)]);

    transport()->findScope('ws_1');
})->with([
    'not JSON' => ['ok'],
    'a missing id' => [['external_ref' => 'ws_1', 'name' => 'Acme', 'created_at' => '2026-11-12T10:00:00Z']],
    'a date that is not one' => [['id' => 'scp_1', 'external_ref' => 'ws_1', 'name' => 'Acme', 'created_at' => 'yesterday-ish at noon?']],
    'a wrong type' => [['id' => 7, 'external_ref' => 'ws_1', 'name' => 'Acme', 'created_at' => '2026-11-12T10:00:00Z']],
])->throws(RelayMalformedResponse::class);

it('refuses an environment it does not know', function (): void {
    Http::fake(['relay.test/*' => Http::response(['organization' => ['id' => 'org_1', 'name' => 'Cbox'], 'environment' => 'staging', 'scope' => null, 'key' => ['id' => 'key_1', 'name' => 'k', 'prefix' => 'p', 'abilities' => []]])]);

    transport()->whoami();
})->throws(RelayMalformedResponse::class);

it('sends nothing and throws when unconfigured', function (string $key): void {
    Http::fake();
    config([$key => '']);

    expect(fn () => transport()->whoami())->toThrow(RelayNotConfigured::class);

    Http::assertNothingSent();
})->with(['relay.url', 'relay.key']);

it('uses the configured timeouts and strips a trailing slash from the url', function (): void {
    config(['relay.url' => 'https://relay.test/', 'relay.timeout' => 7, 'relay.connect_timeout' => 3]);

    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen = ['timeout' => $options['timeout'] ?? null, 'connect_timeout' => $options['connect_timeout'] ?? null];

        return Http::response(['data' => [], 'next_cursor' => null]);
    });

    transport()->listScopes();

    expect($seen)->toBe(['timeout' => 7.0, 'connect_timeout' => 3.0]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://relay.test/v1/scopes');
});

it('falls back to 5 and 2 seconds when the timeouts are unusable', function (): void {
    config(['relay.timeout' => 'soon', 'relay.connect_timeout' => 0]);

    $config = app(RelayConfig::class);

    expect($config->timeout())->toBe(5.0)->and($config->connectTimeout())->toBe(2.0);
});
