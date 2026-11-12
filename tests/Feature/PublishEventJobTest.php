<?php

declare(strict_types=1);

use Cbox\Relay\Client\Contracts\EventPublisher;
use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\NewEvent;
use Cbox\Relay\Client\Exceptions\RelayForbidden;
use Cbox\Relay\Client\Exceptions\RelayInvalid;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Exceptions\RelayRateLimited;
use Cbox\Relay\Client\Exceptions\RelayScopeRequired;
use Cbox\Relay\Client\Exceptions\RelayTooLarge;
use Cbox\Relay\Client\Exceptions\RelayUnauthorized;
use Cbox\Relay\Client\Exceptions\RelayUnavailable;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Jobs\PublishEvent;
use Cbox\Relay\Client\Publishing\ImmediateEventPublisher;
use Cbox\Relay\Client\Publishing\QueuedEventPublisher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

function publishJob(int $tries = 5): PublishEvent
{
    return PublishEvent::for(new NewEvent('order.paid', ['id' => 'o_1'], 'order.paid:o_1'), 'ws_1', $tries, [10, 30, 120]);
}

function runPublishJob(PublishEvent $job): PublishEvent
{
    $job->withFakeQueueInteractions();
    $job->handle(app(RelayTransport::class));

    return $job;
}

/**
 * @return array<string, mixed>
 */
function relayProblem(int $status, string $type): array
{
    return ['type' => 'https://cboxrelay.com/problems/'.$type, 'title' => 'Refused', 'status' => $status, 'detail' => 'Because.'];
}

it('queues a job instead of calling Relay in the caller\'s request', function (): void {
    Bus::fake();

    $key = Relay::forScope('ws_1')->publish('order.paid', ['id' => 'o_1'], occurredAt: new DateTimeImmutable('2026-11-12T10:00:00+01:00'));

    Http::assertNothingSent();

    Bus::assertDispatched(PublishEvent::class, fn (PublishEvent $job): bool => $job->type === 'order.paid'
        && $job->payload === ['id' => 'o_1']
        && $job->idempotencyKey === $key
        && str_starts_with($job->idempotencyKey, 'pub_')
        && $job->occurredAt === '2026-11-12T10:00:00+01:00'
        && $job->scope === 'ws_1'
        && $job->afterCommit === true
        && $job->connection === null
        && $job->queue === null
        && $job->tries === 5
        && $job->backoff === [10, 30, 120, 600]);
});

it('keeps the caller\'s own idempotency key and the configured queue, connection, tries and backoff', function (): void {
    Bus::fake();
    config()->set('relay.publish.connection', 'redis');
    config()->set('relay.publish.queue', 'relay');
    config()->set('relay.publish.tries', 8);
    config()->set('relay.publish.backoff', [5, 60]);

    $key = Relay::forScope('ws_1')->publish('order.paid', [], 'order.paid:o_1');

    expect($key)->toBe('order.paid:o_1');

    Bus::assertDispatched(PublishEvent::class, fn (PublishEvent $job): bool => $job->idempotencyKey === 'order.paid:o_1'
        && $job->connection === 'redis'
        && $job->queue === 'relay'
        && $job->tries === 8
        && $job->backoff === [5, 60]);
});

it('stamps an event without a time with the moment publish was called', function (): void {
    Bus::fake();
    $this->travelTo('2026-11-12 10:00:00');

    Relay::forScope('ws_1')->publish('order.paid');

    Bus::assertDispatched(PublishEvent::class, fn (PublishEvent $job): bool => $job->occurredAt === '2026-11-12T10:00:00+00:00');
});

it('dispatches nothing when the scope is missing', function (): void {
    Bus::fake();

    expect(fn () => Relay::publish('order.paid'))->toThrow(RelayScopeRequired::class);

    Bus::assertNothingDispatched();
});

it('binds the queued publisher by default and the immediate one with relay.publish.sync', function (): void {
    expect(app(EventPublisher::class))->toBeInstanceOf(QueuedEventPublisher::class);

    config()->set('relay.publish.sync', true);

    expect(app(EventPublisher::class))->toBeInstanceOf(ImmediateEventPublisher::class);
});

it('calls Relay inline with relay.publish.sync', function (): void {
    Bus::fake();
    config()->set('relay.publish.sync', true);
    Http::fake(['relay.test/v1/events' => Http::response(['id' => 'evt_1', 'deliveries' => 2, 'duplicate' => false], 202)]);

    $key = Relay::forScope('ws_1')->publish('order.paid', ['id' => 'o_1']);

    Bus::assertNothingDispatched();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://relay.test/v1/events'
        && $request->hasHeader('Relay-Scope', 'ws_1')
        && $request['idempotency_key'] === $key);
});

it('always calls Relay inline from publishNow and answers what Relay said', function (): void {
    Bus::fake();
    Http::fake(['relay.test/v1/events' => Http::response(['id' => 'evt_1', 'deliveries' => 2, 'duplicate' => true], 202)]);

    $published = Relay::forScope('ws_1')->publishNow('order.paid', ['id' => 'o_1'], 'order.paid:o_1');

    expect($published->id)->toBe('evt_1')->and($published->deliveries)->toBe(2)->and($published->duplicate)->toBeTrue();
    Bus::assertNothingDispatched();
});

it('makes the HTTP call from the job with the key fixed at dispatch', function (): void {
    Http::fake(['relay.test/v1/events' => Http::response(['id' => 'evt_1', 'deliveries' => 1, 'duplicate' => false], 202)]);

    $job = runPublishJob(publishJob());

    $job->assertNotFailed();
    $job->assertNotReleased();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Relay-Scope', 'ws_1')
        && $request['type'] === 'order.paid'
        && $request['payload'] === ['id' => 'o_1']
        && $request['idempotency_key'] === 'order.paid:o_1');
});

it('fails the job without retrying for an answer that will never succeed', function (int $status, string $exception): void {
    Http::fake(['relay.test/v1/events' => Http::response(relayProblem($status, 'refused'), $status)]);

    $job = runPublishJob(publishJob());

    $job->assertFailedWith($exception);
    $job->assertNotReleased();
})->with([
    '422' => [422, RelayInvalid::class],
    '403' => [403, RelayForbidden::class],
    '404' => [404, RelayNotFound::class],
    '413' => [413, RelayTooLarge::class],
]);

it('releases the job for as long as Retry-After asks on a 429', function (): void {
    Http::fake(['relay.test/v1/events' => Http::response(relayProblem(429, 'rate_limited'), 429, ['Retry-After' => '42'])]);

    $job = runPublishJob(publishJob());

    $job->assertReleased(42);
    $job->assertNotFailed();
});

it('falls back to its own backoff when a 429 names no Retry-After', function (): void {
    Http::fake(['relay.test/v1/events' => Http::response(relayProblem(429, 'rate_limited'), 429)]);

    runPublishJob(publishJob())->assertReleased(10);
});

it('gives up on a 429 once the attempts are spent', function (): void {
    Http::fake(['relay.test/v1/events' => Http::response(relayProblem(429, 'rate_limited'), 429, ['Retry-After' => '42'])]);

    $job = publishJob(tries: 1)->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(RelayTransport::class)))->toThrow(RelayRateLimited::class);
    $job->assertNotReleased();
});

it('throws so the queue retries on a 5xx, a connection error and a 401', function (Closure $fake, string $exception): void {
    $fake();
    $job = publishJob()->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(RelayTransport::class)))->toThrow($exception);

    $job->assertNotFailed();
    $job->assertNotReleased();
})->with([
    '503' => [fn () => Http::fake(['relay.test/*' => Http::response(relayProblem(503, 'unavailable'), 503)]), RelayUnavailable::class],
    '500' => [fn () => Http::fake(['relay.test/*' => Http::response('oops', 500)]), RelayUnavailable::class],
    'no connection' => [fn () => Http::fake(fn () => throw new ConnectionException('cURL error 7')), RelayUnavailable::class],
    '401' => [fn () => Http::fake(['relay.test/*' => Http::response(relayProblem(401, 'unauthorized'), 401)]), RelayUnauthorized::class],
]);

it('survives the queue: the job serializes and comes back the same', function (): void {
    $job = unserialize(serialize(publishJob()));

    expect($job)->toBeInstanceOf(PublishEvent::class)
        ->and($job->event())->toEqual(new NewEvent('order.paid', ['id' => 'o_1'], 'order.paid:o_1'))
        ->and($job->scope)->toBe('ws_1');
});
