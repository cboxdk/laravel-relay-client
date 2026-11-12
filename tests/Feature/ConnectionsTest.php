<?php

declare(strict_types=1);

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\NewEvent;
use Cbox\Relay\Client\Exceptions\RelayNotConfigured;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Jobs\PublishEvent;
use Cbox\Relay\Client\Relay as RelayManager;
use Cbox\Relay\Client\RelayConfig;
use Cbox\Relay\Client\Testing\FakeRelay;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * @return array<string, mixed>
 */
function whoamiAnswer(string $environment = 'test'): array
{
    return ['organization' => ['id' => 'org_1', 'name' => 'Cbox'], 'environment' => $environment, 'project' => null, 'key' => ['id' => 'key_1', 'name' => 'Server', 'prefix' => 'rlk_test_ab', 'abilities' => []]];
}

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('relay.connections.billing', [
        'url' => 'https://relay-eu.test/',
        'key' => 'rlk_live_billing0123456789',
        'project' => 'billing-ws',
        'timeout' => 9,
        'webhook' => ['secret' => 'whsec_billing', 'previous_secret' => 'whsec_old'],
    ]);
    config()->set('relay.connections.locked', ['key' => 'rlk_test_locked0123456789', 'project_key' => true]);
});

it('reads the top level as the default connection and the others by name', function (): void {
    $config = app(RelayConfig::class);
    $default = $config->connection();
    $billing = $config->connection('billing');
    $locked = $config->connection('locked');

    expect($config->connectionNames())->toBe(['default', 'billing', 'locked'])
        ->and([$default->name, $default->url, $default->project, $default->environment(), $default->configured()])->toBe(['default', 'https://relay.test', null, 'test', true])
        ->and($config->connection('default')->key)->toBe($default->key)
        ->and([$billing->url, $billing->project, $billing->projectKey, $billing->timeout, $billing->connectTimeout, $billing->webhookSecrets, $billing->environment()])
        ->toBe(['https://relay-eu.test', 'billing-ws', false, 9.0, 2.0, ['whsec_billing', 'whsec_old'], 'live'])
        // What a connection does not set comes from the top level.
        ->and([$locked->url, $locked->projectKey, $locked->project, $locked->timeout, $locked->webhookSecrets])->toBe(['https://relay.test', true, null, 5.0, []])
        ->and(fn () => $config->connection('nope'))->toThrow(RelayNotConfigured::class, "No Relay connection is named 'nope'");
});

it('speaks to each connection with its own key, URL and project', function (): void {
    Http::fake(['*' => Http::response(whoamiAnswer())]);

    Relay::whoami();
    Relay::connection('billing')->whoami();
    Relay::connection('locked')->whoami();
    Relay::connection('billing')->connection('default')->whoami();

    $sent = Http::recorded()->map(fn (array $pair): array => [$pair[0]->url(), $pair[0]->header('Authorization')[0] ?? null, $pair[0]->header('Relay-Project')[0] ?? null])->all();

    expect($sent)->toBe([
        ['https://relay.test/v1/whoami', 'Bearer rlk_test_0123456789abcdefghijABCDEFGHIJ0123456789', null],
        ['https://relay-eu.test/v1/whoami', 'Bearer rlk_live_billing0123456789', 'billing-ws'],
        ['https://relay.test/v1/whoami', 'Bearer rlk_test_locked0123456789', null],
        ['https://relay.test/v1/whoami', 'Bearer rlk_test_0123456789abcdefghijABCDEFGHIJ0123456789', null],
    ])
        ->and(Relay::connection('billing')->connectionName())->toBe('billing')
        ->and(Relay::connectionName())->toBeNull()
        ->and(Relay::connection('billing')->project())->toBe('billing-ws');
});

it('says so when a connection has no key, or the client was built by hand', function (): void {
    config()->set('relay.connections.empty', ['project' => 'x']);

    expect(fn () => Relay::connection('empty')->whoami())->toThrow(RelayNotConfigured::class, "The Relay connection 'empty' is not configured")
        ->and(fn () => Relay::connection('nope'))->toThrow(RelayNotConfigured::class)
        ->and(fn () => (new RelayManager(new FakeRelay))->connection('billing'))->toThrow(RelayNotConfigured::class, 'built by hand');
});

it('publishes through the queue for the connection the event was published on', function (): void {
    Bus::fake();

    Relay::connection('billing')->publish('invoice.paid', ['id' => 'inv_1'], 'invoice.paid:inv_1');
    Relay::forProject('ws_1')->publish('order.paid', ['id' => 'o_1'], 'order.paid:o_1');

    Bus::assertDispatched(PublishEvent::class, fn (PublishEvent $job): bool => $job->type === 'invoice.paid' && $job->relayConnection === 'billing' && $job->project === 'billing-ws');
    Bus::assertDispatched(PublishEvent::class, fn (PublishEvent $job): bool => $job->type === 'order.paid' && $job->relayConnection === null);

    // The worker sends it with that connection's key.
    Http::fake(['relay-eu.test/v1/events' => Http::response(['id' => 'evt_1', 'type' => 'invoice.paid', 'duplicate' => false, 'deliveries' => 1], 202)]);
    $job = PublishEvent::for(new NewEvent('invoice.paid', ['id' => 'inv_1'], 'invoice.paid:inv_1'), 'billing-ws', 5, [10], 'billing');
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://relay-eu.test/v1/events' && $request->hasHeader('Authorization', 'Bearer rlk_live_billing0123456789') && $request->hasHeader('Relay-Project', 'billing-ws'));
});

it('sends a mailer\'s mail through the connection the mailer names', function (): void {
    config()->set('mail.mailers.billing', ['transport' => 'relay', 'connection' => 'billing']);
    config()->set('mail.from', ['address' => 'hello@acme.dk', 'name' => 'Acme']);
    Http::fake(['relay-eu.test/v1/mail' => Http::response(['id' => 'msg_1', 'status' => 'queued', 'from' => 'hello@acme.dk', 'subject' => 'Hi', 'reason' => null, 'verdicts' => [], 'recipients' => [], 'tracking' => ['clicks' => false], 'created_at' => '2026-11-12T10:00:00+00:00', 'sent_at' => null], 202)]);

    Mail::mailer('billing')->raw('Thanks', fn ($message) => $message->to('ann@example.com')->subject('Hi'));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://relay-eu.test/v1/mail' && $request->hasHeader('Authorization', 'Bearer rlk_live_billing0123456789') && $request->hasHeader('Relay-Project', 'billing-ws'));
});

it('lets the app bind a transport of its own for one connection, and puts every connection on the fake', function (): void {
    $own = new FakeRelay;
    $this->app->instance('relay.transport.billing', $own);

    expect(Relay::connection('billing')->transport())->toBe($own)
        ->and(Relay::connection('locked')->transport())->not->toBe($own);

    $this->app->forgetInstance('relay.transport.billing');
    $fake = $this->fakeRelay();

    expect(Relay::connection('billing')->transport())->toBe($fake)
        ->and(app(RelayTransport::class))->toBe($fake);

    // On the fake a named connection publishes at once, like the default.
    $fake->seedProject('billing-ws');
    Relay::connection('billing')->publish('invoice.paid', ['id' => 'inv_1']);
    $fake->assertPublished('invoice.paid');
});
