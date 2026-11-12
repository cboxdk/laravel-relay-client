<?php

declare(strict_types=1);

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\AttemptOutcome;
use Cbox\Relay\Client\Data\MailAddress;
use Cbox\Relay\Client\Data\NewEvent;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Data\PingResult;
use Cbox\Relay\Client\Data\Source;
use Cbox\Relay\Client\Data\SourceScheme;
use Cbox\Relay\Client\Exceptions\RelayConflict;
use Cbox\Relay\Client\Exceptions\RelayInvalid;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Jobs\PublishEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function sourceBody(array $extra = []): array
{
    return ['id' => 'src_1', 'name' => 'stripe', 'scheme' => 'stripe', 'url' => 'https://in.cboxrelay.com/src_1', 'event_type' => 'inbound.stripe', 'status' => 'active', 'previous_secret_expires_at' => null, 'last_received_at' => null, 'created_at' => '2026-11-12T10:00:00Z', ...$extra];
}

describe('over HTTP', function (): void {
    beforeEach(fn () => Http::preventStrayRequests());

    it('creates, reads, rotates, pauses, resumes and deletes a source', function (): void {
        Http::fake([
            'relay.test/v1/sources' => Http::sequence()->push(sourceBody(), 201)->push(['data' => [sourceBody()]]),
            'relay.test/v1/sources/src_1' => Http::sequence()->push(sourceBody(['last_received_at' => '2026-11-12T11:00:00Z']))->push('', 204),
            'relay.test/v1/sources/src_2' => Http::response(['type' => 'https://cboxrelay.com/problems/source_not_found', 'title' => 'Not found', 'status' => 404], 404),
            'relay.test/v1/sources/src_1/secret' => Http::response(sourceBody(['previous_secret_expires_at' => '2026-11-13T10:00:00Z'])),
            'relay.test/v1/sources/src_1/pause' => Http::response(sourceBody(['status' => 'paused'])),
            'relay.test/v1/sources/src_1/resume' => Http::response(sourceBody()),
        ]);

        $sources = Relay::forProject('ws_1')->sources();
        $source = $sources->register('stripe', SourceScheme::Stripe, 'whsec_provider', 'src-1');

        expect($source->url)->toBe('https://in.cboxrelay.com/src_1')
            ->and($source->eventType)->toBe('inbound.stripe')
            ->and($source->scheme)->toBe('stripe')
            ->and($source->active())->toBeTrue()
            ->and($sources->list())->toHaveCount(1)
            ->and($sources->find('src_1')?->lastReceivedAt)->not->toBeNull()
            ->and($sources->find('src_2'))->toBeNull()
            ->and($sources->rotateSecret('src_1', 'whsec_new')->previousSecretExpiresAt?->format('Y-m-d'))->toBe('2026-11-13')
            ->and($sources->pause('src_1')->active())->toBeFalse()
            ->and($sources->resume('src_1')->active())->toBeTrue();

        $sources->delete('src_1');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://relay.test/v1/sources'
            && $request->hasHeader('Relay-Project', 'ws_1') && $request->hasHeader('Idempotency-Key', 'src-1')
            && $request->body() === '{"name":"stripe","scheme":"stripe","secret":"whsec_provider"}');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->url() === 'https://relay.test/v1/sources/src_1/secret' && $request->body() === '{"secret":"whsec_new"}');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://relay.test/v1/sources/src_1');
    });

    it('erases a subject', function (): void {
        Http::fake(['relay.test/v1/erasures' => Http::response(['subject' => 'contact_42', 'removed' => 7])]);

        $erasure = Relay::forProject('ws_1')->eraseSubject('contact_42');

        expect($erasure->subject)->toBe('contact_42')->and($erasure->removed)->toBe(7);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->body() === '{"subject":"contact_42"}' && $request->hasHeader('Relay-Project', 'ws_1'));
    });

    it('sends the subject with a publish and leaves it out when there is none', function (): void {
        Http::fake(['relay.test/v1/events' => Http::response(['id' => 'evt_1', 'deliveries' => 0, 'duplicate' => false], 202)]);

        Relay::forProject('ws_1')->publishNow('order.paid', ['id' => 1], 'k1', subject: 'contact_42');
        Relay::forProject('ws_1')->publishNow('order.paid', ['id' => 1], 'k2');

        Http::assertSent(fn (Request $request): bool => $request->body() === '{"type":"order.paid","payload":{"id":1},"idempotency_key":"k1","subject":"contact_42"}');
        Http::assertSent(fn (Request $request): bool => $request->body() === '{"type":"order.paid","payload":{"id":1},"idempotency_key":"k2"}');
    });

    it('reads a domain\'s reputation, with rates that may be null', function (): void {
        Http::fake([
            'relay.test/v1/domains/sdm_1/reputation' => Http::response(['id' => 'sdm_1', 'domain' => 'acme.dk', 'window_days' => 7, 'sent' => 200, 'bounced' => 4, 'complained' => 0, 'bounce_rate' => 0.02, 'complaint_rate' => 0]),
            'relay.test/v1/domains/sdm_2/reputation' => Http::response(['id' => 'sdm_2', 'domain' => 'new.dk', 'window_days' => 7, 'sent' => 0, 'bounced' => 0, 'complained' => 0, 'bounce_rate' => null, 'complaint_rate' => null]),
        ]);

        $reputation = Relay::forProject('ws_1')->domains()->reputation('sdm_1');

        expect($reputation->sent)->toBe(200)
            ->and($reputation->bounced)->toBe(4)
            ->and($reputation->bounceRate)->toBe(0.02)
            ->and($reputation->complaintRate)->toBe(0.0)
            ->and($reputation->windowDays)->toBe(7)
            ->and(Relay::forProject('ws_1')->domains()->reputation('sdm_2')->bounceRate)->toBeNull();
    });

    it('reads a ping outcome as an enum without breaking on one it does not know', function (): void {
        Http::fake(['relay.test/v1/endpoints/ep_1/ping' => Http::sequence()
            ->push(['outcome' => 'success', 'response_code' => 200, 'duration_ms' => 80, 'error' => null])
            ->push(['outcome' => 'breaker_open', 'response_code' => null, 'duration_ms' => 0, 'error' => 'The circuit is open.'])
            ->push(['outcome' => 'something_new', 'response_code' => null, 'duration_ms' => 0, 'error' => null])]);

        $endpoints = Relay::forProject('ws_1')->endpoints();
        [$ok, $open, $unknown] = [$endpoints->ping('ep_1'), $endpoints->ping('ep_1'), $endpoints->ping('ep_1')];

        expect($ok->outcome())->toBe(AttemptOutcome::Success)
            ->and($ok->succeeded())->toBeTrue()
            ->and($open->outcome())->toBe(AttemptOutcome::BreakerOpen)
            ->and($open->succeeded())->toBeFalse()
            ->and($unknown->outcome())->toBeNull()
            ->and($unknown->outcome)->toBe('something_new')
            ->and($unknown->succeeded())->toBeFalse();
    });

    it('sends no Relay-Project header when no project is named', function (): void {
        Http::fake(['relay.test/v1/endpoints' => Http::response(['data' => []])]);

        expect(app(RelayTransport::class)->listEndpoints())->toBe([]);

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Relay-Project'));
    });
});

it('carries the subject through the queued publisher', function (): void {
    Bus::fake();

    Relay::forProject('ws_1')->publish('order.paid', ['id' => 1], subject: 'contact_42');

    Bus::assertDispatched(PublishEvent::class, function (PublishEvent $job): bool {
        $revived = unserialize(serialize($job));

        return $job->subject === 'contact_42' && $revived instanceof PublishEvent && $revived->event()->subject === 'contact_42' && $revived->event()->toArray()['subject'] === 'contact_42';
    });
});

it('refuses a subject Relay would refuse before anything is queued', function (string $subject): void {
    Bus::fake();

    expect(fn () => Relay::forProject('ws_1')->publish('order.paid', subject: $subject))->toThrow(RelayInvalid::class, 'subject');

    Bus::assertNothingDispatched();
})->with([
    'a space' => ['contact 42'],
    'empty' => [''],
    'not ASCII' => ['kontakt_æ'],
    'a control character' => ["contact\n42"],
    'too long' => [str_repeat('a', 192)],
]);

it('takes a subject of 191 visible characters', function (): void {
    expect((new NewEvent('order.paid', subject: str_repeat('a', 191)))->withIdempotencyKey('k')->subject)->toHaveLength(191)
        ->and((new NewEvent('order.paid'))->subject)->toBeNull();
});

describe('on the fake', function (): void {
    beforeEach(function (): void {
        $this->relay = Relay::fake();
        $this->relay->seedProject('ws_1');
        $this->scoped = Relay::forProject('ws_1');
    });

    it('stores sources per project and never gives the secret back', function (): void {
        $this->travelTo('2026-11-12 10:00:00');
        $sources = $this->scoped->sources();

        $source = $sources->register('stripe', SourceScheme::Stripe, 'whsec_provider');

        expect($source->eventType)->toBe('inbound.stripe')
            ->and($source->url)->toStartWith('https://')
            ->and($source->scheme)->toBe('stripe')
            ->and($source->active())->toBeTrue()
            ->and($sources->list())->toHaveCount(1)
            ->and($sources->find($source->id))->toEqual($source)
            ->and($sources->find('src_missing'))->toBeNull()
            ->and(serialize($this->relay->calls()))->not->toContain('whsec_provider');

        $rotated = $sources->rotateSecret($source->id, 'whsec_new');
        expect($rotated->previousSecretExpiresAt?->format('Y-m-d H:i'))->toBe('2026-11-13 10:00')
            ->and($sources->pause($source->id)->status)->toBe('paused')
            ->and($sources->resume($source->id)->status)->toBe('active');

        $this->relay->assertSourceRegistered('stripe');
        $this->relay->assertSourceRegistered('stripe', fn (Source $s, string $secret): bool => $s->scheme === 'stripe' && $secret === 'whsec_new');

        $this->relay->seedProject('ws_2');
        expect(Relay::forProject('ws_2')->sources()->list())->toBe([]);

        $sources->delete($source->id);
        $this->relay->assertNoSourceRegistered();

        expect(fn () => $sources->pause($source->id))->toThrow(RelayNotFound::class)
            ->and(fn () => $this->relay->assertSourceRegistered('stripe'))->toThrow(AssertionFailedError::class);
    });

    it('refuses a bad name, a taken name and a reused idempotency key', function (): void {
        $sources = $this->scoped->sources();
        $first = $sources->register('github', SourceScheme::Github, 's3cret', 'src-1');

        expect($sources->register('github', SourceScheme::Github, 's3cret', 'src-1')->id)->toBe($first->id)
            ->and(fn () => $sources->register('github', SourceScheme::Github, 'other', 'src-1'))->toThrow(RelayConflict::class)
            ->and(fn () => $sources->register('github', SourceScheme::Github, 's3cret'))->toThrow(RelayInvalid::class)
            ->and(fn () => $sources->register('Not Valid', SourceScheme::Cbox, 's3cret'))->toThrow(RelayInvalid::class)
            ->and(fn () => $sources->register('-edge', SourceScheme::Cbox, 's3cret'))->toThrow(RelayInvalid::class)
            ->and(fn () => $sources->register('slack', SourceScheme::Slack, ''))->toThrow(RelayInvalid::class);

        $seeded = $this->relay->seedSource('ws_1', 'resend', status: 'paused');
        expect($seeded->scheme)->toBe('standard-webhooks')->and($sources->find($seeded->id)?->active())->toBeFalse();
    });

    it('erases a subject\'s events with their deliveries, once', function (): void {
        $this->scoped->endpoints()->register('https://customer.example/hooks', ['*']);
        $mine = $this->scoped->publishNow('order.paid', ['id' => 1], subject: 'contact_42');
        $this->scoped->publishNow('order.paid', ['id' => 2], subject: 'contact_42');
        $theirs = $this->scoped->publishNow('order.paid', ['id' => 3], subject: 'contact_7');
        $nobody = $this->scoped->publishNow('order.paid', ['id' => 4]);

        $this->relay->assertNoSubjectErased();

        $erasure = $this->scoped->eraseSubject('contact_42');

        // Two events and one delivery each.
        expect($erasure->subject)->toBe('contact_42')
            ->and($erasure->removed)->toBe(4)
            ->and($this->scoped->events()->find($mine->id))->toBeNull()
            ->and($this->scoped->events()->find($theirs->id)?->deliveries)->toHaveCount(1)
            ->and($this->scoped->events()->find($nobody->id))->not->toBeNull()
            ->and($this->scoped->events()->list())->toHaveCount(2)
            ->and($this->scoped->eraseSubject('contact_42')->removed)->toBe(0);

        $this->relay->assertSubjectErased('contact_42');
        $this->relay->assertSubjectErased('contact_42', 'ws_1');
        $this->relay->assertPublished('order.paid', fn (NewEvent $event): bool => $event->subject === 'contact_7');

        expect(fn () => $this->relay->assertSubjectErased('contact_7'))->toThrow(AssertionFailedError::class)
            ->and(fn () => $this->relay->assertSubjectErased('contact_42', 'ws_2'))->toThrow(AssertionFailedError::class)
            ->and(fn () => $this->relay->assertNoSubjectErased())->toThrow(AssertionFailedError::class);
    });

    it('leaves another project\'s events with the same subject alone', function (): void {
        $this->relay->seedProject('ws_2');
        $other = Relay::forProject('ws_2')->publishNow('order.paid', subject: 'contact_42');

        expect($this->scoped->eraseSubject('contact_42')->removed)->toBe(0)
            ->and(Relay::forProject('ws_2')->events()->find($other->id))->not->toBeNull();
    });

    it('counts a domain\'s reputation from what became of its recipients', function (): void {
        $domain = $this->relay->seedDomain('ws_1', 'acme.dk');
        $domains = $this->scoped->domains();

        expect($domains->reputation($domain->id)->sent)->toBe(0)
            ->and($domains->reputation($domain->id)->bounceRate)->toBeNull();

        $mail = $this->scoped->sendMail(new OutgoingMail(new MailAddress('hello@acme.dk'), ['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'], 'Hi', text: 'Hi'));
        $this->relay->markMail($mail->id, 'sent');
        $this->relay->markRecipient($mail->id, 'b@example.com', 'bounced', '550 no such user');
        $this->relay->markRecipient($mail->id, 'C@example.com', 'complained');

        $reputation = $domains->reputation($domain->id);

        expect($reputation->domain)->toBe('acme.dk')
            ->and($reputation->sent)->toBe(4)
            ->and($reputation->bounced)->toBe(1)
            ->and($reputation->complained)->toBe(1)
            ->and($reputation->bounceRate)->toBe(0.25)
            ->and($reputation->complaintRate)->toBe(0.25)
            ->and($this->scoped->mail($mail->id)?->recipients[1]->detail)->toBe('550 no such user')
            ->and(fn () => $domains->reputation('sdm_missing'))->toThrow(RelayNotFound::class);
    });

    it('answers a ping with a success by default', function (): void {
        $endpoint = $this->relay->seedEndpoint('ws_1');

        expect($this->scoped->endpoints()->ping($endpoint->id)->outcome())->toBe(AttemptOutcome::Success);

        $this->relay->pingAnswers(new PingResult(AttemptOutcome::Timeout->value, null, 10000, 'Timed out.'));

        expect($this->scoped->endpoints()->ping($endpoint->id)->outcome())->toBe(AttemptOutcome::Timeout);
    });

    it('uses the default project for sources and erasures when none is named', function (): void {
        expect(Relay::sources()->list())->toBe([])
            ->and(Relay::eraseSubject('contact_42')->removed)->toBe(0)
            ->and(Relay::projects()->find('default'))->not->toBeNull();
    });
});
