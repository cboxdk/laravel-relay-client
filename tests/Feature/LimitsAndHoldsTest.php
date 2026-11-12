<?php

declare(strict_types=1);

use Cbox\Relay\Client\Contracts\MessageStore;
use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Contracts\SendingLimitsTransport;
use Cbox\Relay\Client\Data\DnsRecord;
use Cbox\Relay\Client\Data\MailAddress;
use Cbox\Relay\Client\Data\MailFilter;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Data\Problem;
use Cbox\Relay\Client\Data\SendingLimitRequest;
use Cbox\Relay\Client\Exceptions\RelayConflict;
use Cbox\Relay\Client\Exceptions\RelayForbidden;
use Cbox\Relay\Client\Exceptions\RelayInvalid;
use Cbox\Relay\Client\Exceptions\RelayNotConfigured;
use Cbox\Relay\Client\Exceptions\RelayRateLimited;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Http\HttpRelayTransport;
use Cbox\Relay\Client\Models\RelayMessage;
use Cbox\Relay\Client\RelayServiceProvider;
use Cbox\Relay\Client\Testing\FakeRelay;
use Cbox\Relay\Client\Testing\Operation;
use Cbox\Relay\Client\Webhooks\Events\MailAccepted;
use Cbox\Relay\Client\Webhooks\Events\MailBlocked;
use Cbox\Relay\Client\Webhooks\Events\MailDiscarded;
use Cbox\Relay\Client\Webhooks\Events\MailReleased;
use Cbox\Relay\Client\Webhooks\Events\ProjectLimitRequestDecided;
use Cbox\Relay\Client\Webhooks\Events\ProjectLimitRequested;
use Cbox\Relay\Client\Webhooks\Events\ProjectOutboundLimited;
use Cbox\Relay\Client\Webhooks\Events\ProjectOutboundLimitsChanged;
use Cbox\Relay\Client\Webhooks\Events\ProjectOutboundPaused;
use Cbox\Relay\Client\Webhooks\Events\ProjectOutboundWatched;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;
use Cbox\Relay\Client\Webhooks\Received;
use Cbox\Relay\Client\Webhooks\ReceivedWebhookFactory;
use Cbox\Relay\Client\Webhooks\RelayEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;

/*
| What Relay's API gained after 0.2.0: sending limits and asking for more, mail held by
| Relay's own guard, finding a mail by its idempotency key or a metadata pair, what a
| look found for each DNS record, and the events about all of it. Every one is additive.
*/

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function heldMail(array $extra = []): array
{
    return [
        'id' => 'msg_1', 'status' => 'queued', 'from' => 'hello@acme.dk', 'subject' => 'Your order', 'reason' => null, 'held_by' => null,
        'tracking' => ['clicks' => false, 'opens' => false], 'tags' => [], 'metadata' => [], 'verdicts' => [],
        'recipients' => [['address' => 'ann@example.com', 'type' => 'to', 'status' => 'queued', 'detail' => null]],
        'idempotency_key' => null, 'created_at' => '2026-11-12T10:00:00Z', 'send_at' => null, 'sent_at' => null,
        ...$extra,
    ];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function limitRequest(array $extra = []): array
{
    return ['id' => 'slr_1', 'project_id' => 'prj_1', 'status' => 'pending', 'hour' => 1500, 'day' => 12000, 'reason' => 'Invoices to our customers.', 'decision' => null, 'created_at' => '2026-11-12T10:00:00Z', ...$extra];
}

function plainMail(string $to = 'ann@example.com'): OutgoingMail
{
    return new OutgoingMail(new MailAddress('hello@acme.dk', 'Acme'), [$to], 'Your order', text: 'Thanks');
}

/**
 * @param  array<string, mixed>  $data
 */
function relayEnvelope(string $type, array $data): string
{
    return (string) json_encode(['id' => 'evt_1', 'type' => $type, 'created_at' => '2026-11-12T10:00:00+00:00', 'delivery_id' => 'whd_1', 'attempt' => 1, 'sequence' => 1, 'scope' => ['partition' => 'org_1', 'key' => 'prj_1'], 'api_version' => null, 'data' => $data]);
}

beforeEach(fn () => Http::preventStrayRequests());

// Over HTTP

it('reads who holds a mail and the key it was accepted with, and finds a mail by key or by one metadata pair', function (): void {
    Http::fake(['relay.test/v1/mail*' => Http::response(['data' => [heldMail(['status' => 'quarantined', 'held_by' => 'relay', 'reason' => 'Held by Relay for a second look.', 'idempotency_key' => 'order-7', 'metadata' => ['order' => '7']])], 'next_cursor' => null])]);

    $byKey = Relay::forProject('ws_1')->mails(MailFilter::idempotencyKey('order-7'));
    $byPair = Relay::forProject('ws_1')->mails(MailFilter::metadata('order', '7'));
    $mail = $byKey->items[0];

    expect($mail->heldBy)->toBe('relay')
        ->and($mail->heldByRelay())->toBeTrue()
        ->and($mail->idempotencyKey)->toBe('order-7')
        ->and($byPair->items[0]->metadata)->toBe(['order' => '7'])
        ->and(MailFilter::idempotencyKey('order-7')->toQuery())->toBe(['idempotency_key' => 'order-7'])
        ->and(MailFilter::metadata('order', '7')->toQuery())->toBe(['metadata' => ['order' => '7']]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_contains(urldecode($request->url()), 'idempotency_key=order-7'));
    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'metadata[order]=7'));
});

it('reads a mail from before Relay said who holds it', function (): void {
    $old = heldMail();
    unset($old['held_by'], $old['idempotency_key']);
    Http::fake(['relay.test/v1/mail/msg_1' => Http::response($old)]);

    $mail = Relay::mail('msg_1');

    expect($mail?->heldBy)->toBeNull()->and($mail?->idempotencyKey)->toBeNull()->and($mail?->heldByRelay())->toBeFalse();
});

it('says whose sending limit was reached, and how long to wait', function (string $scope): void {
    Http::fake(['relay.test/v1/mail' => Http::response(
        ['type' => 'https://cboxrelay.com/problems/sending_limited', 'title' => 'Too Many Requests', 'status' => 429, 'detail' => 'The limit was reached.', 'scope' => $scope],
        429, ['Retry-After' => '1800', 'Content-Type' => 'application/problem+json'],
    )]);

    try {
        Relay::sendMail(plainMail());
        $this->fail('The mail was not refused.');
    } catch (RelayRateLimited $e) {
        expect($e->sendingLimited())->toBeTrue()
            ->and($e->scope())->toBe($scope)
            ->and($e->retryAfter)->toBe(1800)
            ->and($e->type())->toBe('sending_limited')
            ->and($e->problem?->extensions)->toBe(['scope' => $scope])
            ->and($e->problem?->toArray()['scope'] ?? null)->toBe($scope);
    }
})->with(['project', 'organization']);

it('keeps an ordinary rate limit an ordinary rate limit', function (): void {
    Http::fake(['relay.test/v1/whoami' => Http::response(['type' => 'https://cboxrelay.com/problems/rate_limited', 'title' => 'Too Many Requests', 'status' => 429], 429, ['Retry-After' => '30'])]);

    try {
        Relay::whoami();
        $this->fail('The call was not refused.');
    } catch (RelayRateLimited $e) {
        expect($e->sendingLimited())->toBeFalse()->and($e->scope())->toBeNull()->and($e->retryAfter)->toBe(30);
    }
});

it('names the mail the first use of an idempotency key accepted', function (): void {
    Http::fake(['relay.test/v1/mail' => Http::sequence()
        ->push(['type' => 'https://cboxrelay.com/problems/idempotency_key_reused', 'title' => 'Conflict', 'status' => 409, 'mail_id' => 'msg_first'], 409)
        ->push(['type' => 'https://cboxrelay.com/problems/idempotency_key_reused', 'title' => 'Conflict', 'status' => 409], 409)]);

    expect(fn () => Relay::sendMail(plainMail(), 'order-7'))->toThrow(fn (RelayConflict $e) => expect($e->mailId())->toBe('msg_first')->and($e->type())->toBe('idempotency_key_reused'));
    // The first call was not a mail Relay accepted: there is none to name.
    expect(fn () => Relay::sendMail(plainMail(), 'order-7'))->toThrow(fn (RelayConflict $e) => expect($e->mailId())->toBeNull());
});

it('says that Relay holds the mail when a release is refused', function (): void {
    Http::fake(['relay.test/v1/mail/msg_1/release' => Http::response(['type' => 'https://cboxrelay.com/problems/mail_held_by_relay', 'title' => 'Forbidden', 'status' => 403], 403)]);

    expect(fn () => Relay::releaseMail('msg_1'))->toThrow(fn (RelayForbidden $e) => expect($e->heldByRelay())->toBeTrue());
});

it('reads what the last look found for each DNS record of a sending domain', function (): void {
    Http::fake(['relay.test/v1/domains/sdm_1' => Http::response([
        'id' => 'sdm_1', 'domain' => 'acme.dk', 'status' => 'pending', 'verified_at' => null, 'checked_at' => '2026-11-12T10:00:00Z', 'created_at' => '2026-11-12T09:00:00Z',
        'tracking' => ['clicks' => false, 'opens' => false, 'host' => null],
        'records' => [
            ['purpose' => 'dkim', 'type' => 'TXT', 'name' => 'r1._domainkey.acme.dk', 'value' => 'v=DKIM1', 'required' => true, 'status' => 'found'],
            ['purpose' => 'spf', 'type' => 'TXT', 'name' => 'acme.dk', 'value' => 'v=spf1 include:spf.cboxrelay.com ~all', 'required' => false, 'status' => 'different'],
            ['purpose' => 'dmarc', 'type' => 'TXT', 'name' => '_dmarc.acme.dk', 'value' => 'v=DMARC1; p=none', 'required' => false],
        ],
    ])]);

    $records = Relay::domains()->find('sdm_1')->records ?? [];

    expect(array_map(static fn (DnsRecord $record): ?string => $record->status, $records))->toBe(['found', 'different', null])
        ->and($records[0]->found())->toBeTrue()
        ->and($records[1]->found())->toBeFalse();
});

it('reads the sending limits and asks for more', function (): void {
    Http::fake([
        'relay.test/v1/sending-limits' => Http::response([
            'hour' => ['limit' => 500, 'used' => 120, 'origin' => 'default'],
            'day' => ['limit' => 5000, 'used' => 4950, 'origin' => 'auto'],
            'standing' => 'good',
            'open_request' => limitRequest(),
            'changes' => [['origin' => 'auto', 'hour' => 500, 'day' => 5000, 'previous_hour' => 500, 'previous_day' => 4000, 'reason' => 'Normal use grew.', 'created_at' => '2026-11-11T04:23:00Z']],
        ]),
        'relay.test/v1/sending-limit-requests/slr_1' => Http::response(limitRequest(['status' => 'approved', 'decision' => ['hour' => 1200, 'day' => 12000, 'reason' => 'Fine.', 'decided_by' => 'operator', 'decided_at' => '2026-11-12T11:00:00Z']])),
        'relay.test/v1/sending-limit-requests/slr_gone' => Http::response(['type' => 'https://cboxrelay.com/problems/limit_request_not_found', 'title' => 'Not found', 'status' => 404], 404),
        'relay.test/v1/sending-limit-requests' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(limitRequest(), 201)
            : Http::response(['data' => [limitRequest(), limitRequest(['id' => 'slr_0', 'status' => 'declined', 'decision' => ['hour' => null, 'day' => null, 'reason' => 'Too early.', 'decided_by' => 'operator', 'decided_at' => '2026-11-10T11:00:00Z']])]]),
    ]);

    $limits = Relay::forProject('ws_1')->sendingLimits()->current();
    $asked = Relay::forProject('ws_1')->sendingLimits()->request(1500, 12000, 'Invoices to our customers.', 'ask-1');
    $all = Relay::forProject('ws_1')->sendingLimits()->requests();
    $answered = Relay::forProject('ws_1')->sendingLimits()->findRequest('slr_1');

    expect($limits->hour->limit)->toBe(500)
        ->and($limits->hour->remaining())->toBe(380)
        ->and($limits->day->origin)->toBe('auto')
        ->and($limits->remaining())->toBe(50)
        ->and($limits->standing)->toBe('good')
        ->and($limits->openRequest?->id)->toBe('slr_1')
        ->and($limits->changes[0]->previousDay)->toBe(4000)
        ->and($limits->changes[0]->createdAt->format('H:i'))->toBe('04:23')
        ->and($asked->pending())->toBeTrue()
        ->and($asked->decision)->toBeNull()
        ->and($all)->toHaveCount(2)
        ->and($all[1]->declined())->toBeTrue()
        ->and($all[1]->decision?->hour)->toBeNull()
        ->and($all[1]->decision?->reason)->toBe('Too early.')
        ->and($answered?->approved())->toBeTrue()
        ->and($answered?->decision?->hour)->toBe(1200)
        ->and($answered?->decision?->decidedBy)->toBe('operator')
        ->and(Relay::forProject('ws_1')->sendingLimits()->findRequest('slr_gone'))->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://relay.test/v1/sending-limit-requests'
        && $request->data() === ['hour' => 1500, 'day' => 12000, 'reason' => 'Invoices to our customers.']
        && $request->header('Idempotency-Key') === ['ask-1']
        && $request->header('Relay-Project') === ['ws_1']);
});

it('says so when a transport of your own does not make the sending-limit calls', function (): void {
    expect(app(RelayTransport::class))->toBeInstanceOf(HttpRelayTransport::class)->toBeInstanceOf(SendingLimitsTransport::class);

    $own = Mockery::mock(RelayTransport::class);
    $this->app->instance(RelayTransport::class, $own);
    Relay::clearResolvedInstances();
    $this->app->forgetInstance(Cbox\Relay\Client\Relay::class);

    expect(fn () => app(Cbox\Relay\Client\Relay::class)->sendingLimits()->current())->toThrow(RelayNotConfigured::class, SendingLimitsTransport::class);
});

// Events

it('reads the events about holds, limits and requests', function (string $type, array $data, string $class, Closure $check): void {
    $webhook = IncomingWebhook::parse(relayEnvelope($type, $data));
    $event = $webhook->event();

    expect($event)->toBeInstanceOf($class)->toBeInstanceOf(RelayEvent::class)
        ->and($class::type())->toBe($type)
        ->and($check($event))->toBeTrue()
        ->and(new ReceivedWebhookFactory()->make($webhook, 'billing'))->toBeInstanceOf(str_replace('\\Events\\', '\\Received\\', $class));
})->with([
    'mail.accepted with a state' => ['mail.accepted', ['id' => 'msg_1', 'project_id' => 'prj_1', 'from' => 'hello@acme.dk', 'recipients' => 1, 'suppressed' => 0, 'status' => 'queued', 'state' => 'accepted', 'held_by' => null, 'reason' => null],
        MailAccepted::class, fn (MailAccepted $e): bool => $e->state === 'accepted' && $e->heldBy === null],
    'mail.accepted from before' => ['mail.accepted', ['id' => 'msg_1', 'project_id' => 'prj_1', 'from' => 'hello@acme.dk', 'recipients' => 1, 'suppressed' => 0, 'status' => 'queued', 'reason' => null],
        MailAccepted::class, fn (MailAccepted $e): bool => $e->state === null && $e->heldBy === null],
    'mail.blocked, held by Relay' => ['mail.blocked', ['id' => 'msg_1', 'project_id' => 'prj_1', 'from' => 'hello@acme.dk', 'recipients' => 1, 'suppressed' => 0, 'status' => 'quarantined', 'state' => 'held', 'held_by' => 'relay', 'reason' => 'Held by Relay for a second look.'],
        MailBlocked::class, fn (MailBlocked $e): bool => $e->held() && $e->heldByRelay() && $e->state === 'held'],
    'mail.blocked, held by the project' => ['mail.blocked', ['id' => 'msg_1', 'project_id' => 'prj_1', 'from' => 'hello@acme.dk', 'recipients' => 1, 'suppressed' => 0, 'status' => 'quarantined', 'state' => 'held', 'held_by' => 'project', 'reason' => 'phishing 0.8'],
        MailBlocked::class, fn (MailBlocked $e): bool => $e->held() && ! $e->heldByRelay()],
    'mail.blocked, never sent' => ['mail.blocked', ['id' => 'msg_1', 'project_id' => 'prj_1', 'from' => 'hello@acme.dk', 'recipients' => 1, 'suppressed' => 1, 'status' => 'blocked', 'state' => 'blocked', 'held_by' => null, 'reason' => 'Every recipient is suppressed.'],
        MailBlocked::class, fn (MailBlocked $e): bool => ! $e->held() && ! $e->heldByRelay()],
    'mail.released' => ['mail.released', ['id' => 'msg_1', 'project_id' => 'prj_1', 'by' => 'relay', 'status' => 'scheduled', 'tags' => ['invoice'], 'metadata' => ['order' => '7']],
        MailReleased::class, fn (MailReleased $e): bool => $e->id === 'msg_1' && $e->by === 'relay' && $e->status === 'scheduled' && $e->tags === ['invoice'] && $e->metadata === ['order' => '7']],
    'mail.discarded' => ['mail.discarded', ['id' => 'msg_1', 'project_id' => 'prj_1', 'by' => 'project'],
        MailDiscarded::class, fn (MailDiscarded $e): bool => $e->id === 'msg_1' && $e->by === 'project' && $e->tags === []],
    'project.outbound_limited' => ['project.outbound_limited', ['project_id' => 'prj_1', 'scope' => 'organization', 'window' => 'day', 'limit' => 50000, 'used' => 50000],
        ProjectOutboundLimited::class, fn (ProjectOutboundLimited $e): bool => $e->scope === 'organization' && $e->window === 'day' && $e->limit === 50000 && $e->used === 50000],
    'project.outbound_limited from before' => ['project.outbound_limited', ['project_id' => 'prj_1', 'window' => 'hour', 'limit' => 500, 'used' => 500],
        ProjectOutboundLimited::class, fn (ProjectOutboundLimited $e): bool => $e->scope === 'project' && $e->window === 'hour'],
    'project.outbound_watched' => ['project.outbound_watched', ['project_id' => 'prj_1', 'reason' => 'It sends unlike itself.', 'cause' => 'spike'],
        ProjectOutboundWatched::class, fn (ProjectOutboundWatched $e): bool => $e->cause === 'spike' && $e->reason === 'It sends unlike itself.'],
    'project.outbound_paused for bounces' => ['project.outbound_paused', ['project_id' => 'prj_1', 'reason' => 'A burst of hard bounces.', 'cause' => 'bounces'],
        ProjectOutboundPaused::class, fn (ProjectOutboundPaused $e): bool => $e->cause === 'bounces' && $e->sent === 0 && $e->bounced === 0],
    'project.outbound_paused for rates' => ['project.outbound_paused', ['project_id' => 'prj_1', 'reason' => 'Too much bounced.', 'cause' => 'rates', 'sent' => 400, 'bounced' => 30, 'complained' => 1],
        ProjectOutboundPaused::class, fn (ProjectOutboundPaused $e): bool => $e->cause === 'rates' && $e->sent === 400 && $e->bounced === 30 && $e->complained === 1],
    'project.outbound_limits_changed' => ['project.outbound_limits_changed', ['project_id' => 'prj_1', 'origin' => 'auto', 'hour' => 750, 'day' => 7500, 'previous_hour' => 500, 'previous_day' => 5000, 'reason' => 'Normal use grew.'],
        ProjectOutboundLimitsChanged::class, fn (ProjectOutboundLimitsChanged $e): bool => $e->origin === 'auto' && $e->hour === 750 && $e->previousDay === 5000 && $e->reason === 'Normal use grew.'],
    'project.limit_requested' => ['project.limit_requested', ['project_id' => 'prj_1', 'request_id' => 'slr_1', 'hour' => 1500, 'day' => 12000],
        ProjectLimitRequested::class, fn (ProjectLimitRequested $e): bool => $e->requestId === 'slr_1' && $e->hour === 1500 && $e->day === 12000],
    'project.limit_request_decided, granted' => ['project.limit_request_decided', ['project_id' => 'prj_1', 'request_id' => 'slr_1', 'status' => 'approved', 'hour' => 1200, 'day' => 12000, 'reason' => null, 'decided_by' => 'auto'],
        ProjectLimitRequestDecided::class, fn (ProjectLimitRequestDecided $e): bool => $e->approved() && $e->hour === 1200 && $e->decidedBy === 'auto' && $e->reason === null],
    'project.limit_request_decided, declined' => ['project.limit_request_decided', ['project_id' => 'prj_1', 'request_id' => 'slr_1', 'status' => 'declined', 'hour' => null, 'day' => null, 'reason' => 'Too early.', 'decided_by' => 'operator'],
        ProjectLimitRequestDecided::class, fn (ProjectLimitRequestDecided $e): bool => ! $e->approved() && $e->hour === null && $e->day === null && $e->reason === 'Too early.'],
]);

it('carries the connection on the Laravel events for the new types', function (): void {
    $received = new ReceivedWebhookFactory()->make(IncomingWebhook::parse(relayEnvelope('mail.released', ['id' => 'msg_1', 'project_id' => 'prj_1', 'by' => 'operator', 'status' => 'queued'])), 'billing');

    expect($received)->toBeInstanceOf(Received\MailReleased::class)
        ->and($received->connection())->toBe('billing')
        ->and($received->webhook()->type)->toBe('mail.released');
});

it('keeps a held, a released and a discarded mail right in the local store', function (): void {
    config()->set('relay.store.enabled', true);
    (require __DIR__.'/../../database/migrations/create_relay_messages_tables.php.stub')->up();
    (new RelayServiceProvider(app()))->boot();
    $relay = $this->fakeRelay();
    $relay->seedProject('ws_1');
    $held = Relay::forProject('ws_1')->sendMail(plainMail());
    $gone = Relay::forProject('ws_1')->sendMail(plainMail('bo@example.com'));
    $store = app(MessageStore::class);
    $event = fn (string $type, array $data, string $delivery): IncomingWebhook => new IncomingWebhook('evt_'.$delivery, $type, $data, occurredAt: new DateTimeImmutable('2026-11-12T10:00:00+00:00'), deliveryId: $delivery);

    $store->recordEvent($event('mail.blocked', ['id' => $held->id, 'state' => 'held', 'held_by' => 'relay'], 'whd_1'));
    expect(RelayMessage::query()->findOrFail($held->id)->status)->toBe('quarantined');

    // Let go: on its way again, and a delivery after that still counts.
    $store->recordEvent($event('mail.released', ['id' => $held->id, 'by' => 'relay', 'status' => 'queued'], 'whd_2'));
    expect(RelayMessage::query()->findOrFail($held->id)->status)->toBe('queued');
    $store->recordEvent($event('mail.delivered', ['id' => $held->id, 'recipient' => 'ann@example.com'], 'whd_3'));
    expect(RelayMessage::query()->findOrFail($held->id)->status)->toBe('delivered');

    $store->recordEvent($event('mail.blocked', ['id' => $gone->id, 'state' => 'held', 'held_by' => 'project'], 'whd_4'));
    $store->recordEvent($event('mail.discarded', ['id' => $gone->id, 'by' => 'project'], 'whd_5'));
    expect(RelayMessage::query()->findOrFail($gone->id)->status)->toBe('blocked')
        ->and(RelayMessage::query()->findOrFail($gone->id)->events->pluck('type')->all())->toBe(['mail.blocked', 'mail.discarded']);

    // An event from before Relay said "held": blocked, as in 0.2.0.
    $old = Relay::forProject('ws_1')->sendMail(plainMail('cy@example.com'));
    $store->recordEvent($event('mail.blocked', ['id' => $old->id], 'whd_6'));
    expect(RelayMessage::query()->findOrFail($old->id)->status)->toBe('blocked');
});

// The fake

it('refuses mail for a sending limit in the fake, by project or by organization, until it is lifted', function (): void {
    $relay = $this->fakeRelay();
    $relay->seedProject('ws_1');
    $relay->seedProject('ws_2');
    $relay->limitSending('ws_1', 'organization', 900);

    try {
        Relay::forProject('ws_1')->sendMail(plainMail());
        $this->fail('The mail was not refused.');
    } catch (RelayRateLimited $e) {
        expect($e->sendingLimited())->toBeTrue()->and($e->scope())->toBe('organization')->and($e->retryAfter)->toBe(900)->and($e->status())->toBe(429);
    }

    // Another project is not limited, and nothing of the refused mail was kept.
    expect(Relay::forProject('ws_2')->sendMail(plainMail())->status)->toBe('queued')
        ->and(Relay::forProject('ws_1')->mails()->items)->toBe([]);

    $relay->liftSendingLimit('ws_1');
    expect(Relay::forProject('ws_1')->sendMail(plainMail())->accepted())->toBeTrue();

    // Every project at once.
    $relay->limitSending();
    expect(fn () => Relay::forProject('ws_2')->sendMail(plainMail()))->toThrow(fn (RelayRateLimited $e) => expect($e->scope())->toBe('project'));
});

it('counts recipients against seeded limits in the fake, and a batch refuses only what does not fit', function (): void {
    Carbon::setTestNow('2026-11-12 10:00:00');
    $relay = $this->fakeRelay();
    $relay->seedSendingLimits('ws_1', 2, 3, 'operator', 'good');
    $acting = Relay::forProject('ws_1');

    $results = $acting->sendMailBatch([plainMail('a@example.com'), plainMail('b@example.com'), plainMail('c@example.com')]);

    expect(array_map(static fn ($result): int => $result->status, $results))->toBe([202, 202, 429])
        ->and($results[2]->problem?->extension('scope'))->toBe('project');

    $limits = $acting->sendingLimits()->current();
    expect($limits->hour->limit)->toBe(2)->and($limits->hour->used)->toBe(2)->and($limits->remaining())->toBe(0)
        ->and($limits->hour->origin)->toBe('operator')->and($limits->standing)->toBe('good');

    // An hour later the hour has room again; the day has one left.
    Carbon::setTestNow('2026-11-12 11:00:01');
    expect($acting->sendingLimits()->current()->hour->used)->toBe(0)
        ->and($acting->sendMail(plainMail('d@example.com'))->accepted())->toBeTrue();
    expect(fn () => $acting->sendMail(plainMail('e@example.com')))->toThrow(fn (RelayRateLimited $e) => expect($e->retryAfter)->toBe(86400));

    Carbon::setTestNow();
});

it('asks for higher limits in the fake: waiting, granted at once, answered by the operator, and one at a time', function (): void {
    $relay = $this->fakeRelay();
    $relay->seedProject('ws_1');
    $limits = Relay::forProject('ws_1')->sendingLimits();

    $relay->assertNoSendingLimitRequested();
    expect($limits->current()->hour->limit)->toBe(FakeRelay::DEFAULT_HOUR_LIMIT)->and($limits->current()->hour->origin)->toBe('default');

    $asked = $limits->request(1500, 12000, 'Invoices to our customers.', 'ask-1');
    expect($asked->pending())->toBeTrue()
        // The same key and request: the same answer, not a second request.
        ->and($limits->request(1500, 12000, 'Invoices to our customers.', 'ask-1')->id)->toBe($asked->id)
        ->and($limits->current()->openRequest?->id)->toBe($asked->id)
        ->and($limits->requests())->toHaveCount(1)
        ->and($limits->findRequest($asked->id)?->hour)->toBe(1500)
        ->and($limits->findRequest('slr_nope'))->toBeNull();

    expect(fn () => $limits->request(2000, 20000, 'More invoices to customers.'))->toThrow(fn (RelayConflict $e) => expect($e->type())->toBe('limit_request_open'));

    // Relay's operator grants less than was asked.
    $decided = $relay->decideSendingLimitRequest($asked->id, true, 1200, 12000, 'Fine, a little less.');
    expect($decided->approved())->toBeTrue()
        ->and($decided->decision?->hour)->toBe(1200)
        ->and($decided->decision?->decidedBy)->toBe('operator')
        ->and($limits->current()->hour->limit)->toBe(1200)
        ->and($limits->current()->hour->origin)->toBe('request')
        ->and($limits->current()->openRequest)->toBeNull()
        ->and($limits->current()->changes[0]->previousHour)->toBe(500);

    // Asking for less, the same, too short a reason or a day under the hour is refused.
    expect(fn () => $limits->request(1000, 12000, 'Less than we have now.'))->toThrow(RelayInvalid::class)
        ->and(fn () => $limits->request(1200, 12000, 'The same as we have now.'))->toThrow(RelayInvalid::class)
        ->and(fn () => $limits->request(5000, 50000, 'Short'))->toThrow(RelayInvalid::class)
        ->and(fn () => $limits->request(5000, 4000, 'A day under the hour asked.'))->toThrow(RelayInvalid::class);

    // In good standing Relay grants at once.
    $relay->grantLimitRequestsAtOnce();
    $granted = $limits->request(3000, 30000, 'A newsletter to our subscribers.');
    expect($granted->approved())->toBeTrue()->and($granted->decision?->decidedBy)->toBe('auto')->and($limits->current()->day->limit)->toBe(30000);

    // And a declined one changes nothing.
    $relay->grantLimitRequestsAtOnce(false);
    $third = $limits->request(9000, 90000, 'Far more than we ever sent.');
    expect($relay->decideSendingLimitRequest($third->id, false, reason: 'Too early.')->declined())->toBeTrue()
        ->and($limits->current()->hour->limit)->toBe(3000)
        ->and($limits->requests()[0]->decision?->reason)->toBe('Too early.');

    $relay->assertSendingLimitRequested();
    $relay->assertSendingLimitRequested(fn (SendingLimitRequest $request): bool => $request->hour === 1500 && $request->approved());
    $relay->assertCalledTimes(Operation::CreateSendingLimitRequest, 9);
    expect(fn () => $relay->assertSendingLimitRequested(fn (SendingLimitRequest $request): bool => $request->hour === 7))->toThrow(AssertionFailedError::class)
        ->and(fn () => $relay->assertNoSendingLimitRequested())->toThrow(AssertionFailedError::class)
        ->and(fn () => $relay->decideSendingLimitRequest('slr_nope', true))->toThrow(InvalidArgumentException::class);
});

it('holds a mail as Relay does in the fake: it can be discarded, not released', function (): void {
    $relay = $this->fakeRelay();
    $relay->seedProject('ws_1');
    $acting = Relay::forProject('ws_1');
    $mail = $acting->sendMail(plainMail());
    $own = $acting->sendMail(plainMail('bo@example.com'));

    $relay->holdMailByRelay($mail->id, 'Held by Relay for a second look.');
    $relay->markMail($own->id, 'quarantined', 'phishing 0.8');

    $held = $acting->mail($mail->id);
    expect($held?->status)->toBe('quarantined')->and($held?->heldBy)->toBe('relay')->and($held?->heldByRelay())->toBeTrue()
        ->and($acting->mail($own->id)?->heldBy)->toBe('project');

    expect(fn () => $acting->releaseMail($mail->id))->toThrow(fn (RelayForbidden $e) => expect($e->heldByRelay())->toBeTrue()->and($e->status())->toBe(403));
    // Its own hold it may let go, and then nobody holds it.
    expect($acting->releaseMail($own->id)->heldBy)->toBeNull()
        ->and($acting->discardMail($mail->id)->status)->toBe('blocked')
        ->and($acting->mail($mail->id)?->heldBy)->toBeNull()
        ->and(fn () => $relay->holdMailByRelay('msg_nope'))->toThrow(InvalidArgumentException::class);
});

it('keeps the idempotency key on a mail in the fake, finds by it and by metadata, and names the first mail on a conflict', function (): void {
    $relay = $this->fakeRelay();
    $relay->seedProject('ws_1');
    $acting = Relay::forProject('ws_1');
    $first = $acting->sendMail(new OutgoingMail(new MailAddress('hello@acme.dk'), ['ann@example.com'], 'Order 7', text: 'Thanks', metadata: ['order' => '7']), 'order-7');
    $acting->sendMail(new OutgoingMail(new MailAddress('hello@acme.dk'), ['bo@example.com'], 'Order 8', text: 'Thanks', metadata: ['order' => '8']));

    expect($first->idempotencyKey)->toBe('order-7')
        ->and($acting->mails(MailFilter::idempotencyKey('order-7'))->items)->toHaveCount(1)
        ->and($acting->mails(MailFilter::idempotencyKey('order-7'))->items[0]->id)->toBe($first->id)
        ->and($acting->mails(MailFilter::metadata('order', '8'))->items[0]->subject)->toBe('Order 8')
        ->and($acting->mails(MailFilter::metadata('order', '9'))->items)->toBe([])
        ->and($acting->mails(MailFilter::idempotencyKey('nope'))->items)->toBe([]);

    // The retry rendered other content: a conflict that says the first one left.
    expect(fn () => $acting->sendMail(new OutgoingMail(new MailAddress('hello@acme.dk'), ['ann@example.com'], 'Order 7 (again)', text: 'Thanks'), 'order-7'))
        ->toThrow(fn (RelayConflict $e) => expect($e->mailId())->toBe($first->id)->and($e->type())->toBe('idempotency_key_reused'));
});

it('says what a look found for each record of a domain in the fake', function (): void {
    $relay = $this->fakeRelay();
    $relay->seedProject('ws_1');
    $domains = Relay::forProject('ws_1')->domains();
    $domain = $domains->register('acme.dk');
    $status = static fn ($domain): array => array_map(static fn (DnsRecord $record): ?string => $record->status, $domain->records);

    expect($status($domain))->toBe(['unchecked', 'unchecked', 'unchecked']);

    $relay->dnsPublished(false);
    expect($status($domains->verify($domain->id)))->toBe(['missing', 'missing', 'missing']);

    $relay->dnsPublished();
    expect($status($domains->verify($domain->id)))->toBe(['found', 'found', 'found']);
});

it('reads a problem document\'s own members and nothing it should not', function (): void {
    $problem = Problem::fromArray(['type' => 'https://cboxrelay.com/problems/sending_limited', 'title' => 'Too Many Requests', 'status' => 429, 'detail' => 'x', 'instance' => '/v1/mail', 'scope' => 'project', 'retry_after' => 60, 7 => 'odd'], 429);

    expect($problem->extensions)->toBe(['scope' => 'project', 'retry_after' => 60])
        ->and($problem->extension('scope'))->toBe('project')
        ->and($problem->extension('retry_after'))->toBeNull()
        ->and($problem->extension('nope'))->toBeNull()
        ->and(Problem::fromArray([], 500)->extensions)->toBe([]);
});
