<?php

declare(strict_types=1);

use Cbox\LaravelHealth\Enums\Status;
use Cbox\Relay\Client\Data\ApiKey;
use Cbox\Relay\Client\Data\Environment;
use Cbox\Relay\Client\Data\Identity;
use Cbox\Relay\Client\Data\Organization;
use Cbox\Relay\Client\Exceptions\RelayUnavailable;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Health\RelayConnectionCheck;
use Cbox\Relay\Client\Support\Doctor;
use Cbox\Relay\Client\Support\DoctorCheck;
use Cbox\Relay\Client\Support\DoctorStatus;
use Illuminate\Support\Facades\Artisan;

/**
 * @param  list<DoctorCheck>  $checks
 * @return array<string, string> "section / name" => status
 */
function doctorSummary(array $checks): array
{
    $summary = [];

    foreach ($checks as $check) {
        $summary["{$check->section} / {$check->name}"] = $check->status->value;
    }

    return $summary;
}

beforeEach(function (): void {
    $this->relay = $this->fakeRelay();
    $this->relay->actingAs(new Identity(new Organization('org_1', 'Acme'), Environment::Test, new ApiKey('key_1', 'Server', 'rlk_test_ab', [])));
});

it('reports a setup with everything in place', function (): void {
    config()->set('relay.project', 'ws_1');
    config()->set('relay.webhook.secret', 'whsec_1');
    config()->set('mail.default', 'relay');
    config()->set('mail.mailers.relay', ['transport' => 'relay']);
    $this->relay->seedProject('ws_1');
    Relay::webhookRoutes();
    app('router')->getRoutes()->refreshNameLookups();

    $checks = app(Doctor::class)->run();

    expect(doctorSummary($checks))->toBe([
        'connection default / Key' => 'ok',
        'connection default / Webhook secret' => 'ok',
        'connection default / Relay answers' => 'ok',
        'connection default / Project' => 'ok',
        'webhooks / Route' => 'ok',
        'store / Local delivery status' => 'ok',
        'mail / Mailer' => 'ok',
    ])
        ->and(collect($checks)->firstWhere('name', 'Relay answers')?->message)->toContain('Acme, test, key "Server"')
        ->and(collect($checks)->firstWhere('name', 'Mailer')?->message)->toBe('relay (the default mailer)');

    $this->artisan('relay:doctor')->expectsOutputToContain('Everything is in place.')->assertSuccessful();
});

it('fails on what would break and only warns about what might be meant', function (): void {
    config()->set('relay.project', 'missing-ws');
    config()->set('relay.store.enabled', true);
    config()->set('relay.connections.billing', ['project' => 'x']);
    config()->set('relay.connections.live', ['key' => 'rlk_live_0123456789']);
    Relay::webhookRoutes();
    app('router')->getRoutes()->refreshNameLookups();

    $summary = doctorSummary(app(Doctor::class)->run());

    expect($summary)->toMatchArray([
        'connection default / Webhook secret' => 'warning',
        // Named, but Relay has no such project.
        'connection default / Project' => 'failure',
        'connection billing / Key and URL' => 'failure',
        // The prefix says live, Relay says test.
        'connection live / Environment' => 'failure',
        // No project named is fine: Relay uses the default one.
        'connection live / Project' => 'ok',
        // A route with no secret refuses every delivery.
        'webhooks / Secret' => 'failure',
        'store / Table relay_messages' => 'failure',
        'store / Table relay_message_events' => 'failure',
        'mail / Mailer' => 'warning',
    ]);

    $this->artisan('relay:doctor')->expectsOutputToContain('Fix what failed')->assertFailed();
});

it('looks at the configuration alone with --no-ping, and says when Relay does not answer', function (): void {
    $this->relay->failWith(new RelayUnavailable('Cbox Relay did not answer.'));

    expect(doctorSummary(app(Doctor::class)->run(ping: false)))->toMatchArray([
        'connection default / Key' => 'ok',
        'connection default / Project' => 'ok',
        'webhooks / Route' => 'warning',
    ])
        ->and(doctorSummary(app(Doctor::class)->run()))->toMatchArray(['connection default / Relay answers' => 'failure']);

    $this->artisan('relay:doctor', ['--no-ping' => true])->expectsOutputToContain('Nothing failed.')->assertSuccessful();
    $this->artisan('relay:ping')->expectsOutputToContain('Cbox Relay did not answer.')->assertFailed();
});

it('never prints a key or a secret', function (): void {
    config()->set('relay.webhook.secret', 'whsec_never_shown');
    Artisan::call('relay:doctor');
    $output = Artisan::output();
    Artisan::call('relay:ping');
    $output .= Artisan::output();

    expect($output)->not->toContain('whsec_never_shown')
        ->and($output)->not->toContain((string) config('relay.key'));
});

it('pings a connection and says who the key is', function (): void {
    config()->set('relay.connections.billing', ['key' => 'rlk_test_billing']);

    $this->artisan('relay:ping')
        ->expectsOutputToContain('default: Relay answered in')
        ->expectsOutputToContain('Acme')
        ->assertSuccessful();
    $this->artisan('relay:ping', ['connection' => 'billing'])->expectsOutputToContain('billing: Relay answered in')->assertSuccessful();
    $this->artisan('relay:ping', ['connection' => 'nope'])->assertFailed();
});

it('tails the delivery log', function (): void {
    $this->relay->seedDeliveryFor('ws_1', 'failed', 'webhook', 'https://acme.dk/hooks', responseCode: 500, error: 'Internal Server Error');
    $this->relay->seedDeliveryFor('ws_1', 'delivered', 'mail', 'ann@example.com', responseCode: 250);

    $this->artisan('relay:tail', ['--project' => 'ws_1'])
        ->expectsOutputToContain('https://acme.dk/hooks')
        ->expectsOutputToContain('ann@example.com')
        ->assertSuccessful();
    $this->artisan('relay:tail', ['--project' => 'ws_1', '--channel' => 'mail', '--limit' => '5'])
        ->expectsOutputToContain('ann@example.com')
        ->doesntExpectOutputToContain('https://acme.dk/hooks')
        ->assertSuccessful();
    $this->artisan('relay:tail', ['--project' => 'ws_1', '--status' => 'expired'])->expectsOutputToContain('Nothing in the delivery log matches.')->assertSuccessful();

    $this->relay->failWith(new RelayUnavailable('Cbox Relay did not answer.'));
    $this->artisan('relay:tail', ['--project' => 'ws_1'])->assertFailed();
});

it('publishes the config and says what to set', function (): void {
    $this->artisan('relay:install')
        ->expectsOutputToContain('RELAY_KEY')
        ->expectsOutputToContain('RELAY_WEBHOOK_SECRET')
        ->expectsOutputToContain('php artisan relay:doctor')
        ->doesntExpectOutputToContain('RELAY_STORE=true')
        ->assertSuccessful();
    $this->artisan('relay:install', ['--store' => true, '--force' => true])->expectsOutputToContain('RELAY_STORE=true')->assertSuccessful();

    expect(file_exists(config_path('relay.php')))->toBeTrue()
        ->and(glob(database_path('migrations/*_create_relay_messages_tables.php')))->not->toBeEmpty();
})->after(function (): void {
    @unlink(config_path('relay.php'));
    array_map(unlink(...), glob(database_path('migrations/*_create_relay_messages_tables.php')) ?: []);
});

it('answers the health package for every connection', function (): void {
    config()->set('relay.connections.billing', ['key' => 'rlk_test_billing']);
    $check = app(RelayConnectionCheck::class);

    $ok = $check->run();

    expect($check->name())->toBe('relay')
        ->and($ok->status)->toBe(Status::Ok)
        ->and(array_keys($ok->metadata))->toBe(['default', 'billing'])
        ->and($ok->metadata['default']['environment'])->toBe('test');

    $this->relay->failWith(new RelayUnavailable('Cbox Relay did not answer.'));
    $down = $check->run();

    expect($down->status)->toBe(Status::Critical)
        ->and($down->message)->toContain('default: Cbox Relay did not answer.');
});

it('gives a status every check can be read by', function (): void {
    expect(DoctorCheck::ok('a', 'b')->status)->toBe(DoctorStatus::Ok)
        ->and(DoctorCheck::warning('a', 'b', 'c')->status)->toBe(DoctorStatus::Warning)
        ->and(DoctorCheck::failure('a', 'b', 'c')->message)->toBe('c');
});
