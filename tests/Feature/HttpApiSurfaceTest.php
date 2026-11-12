<?php

declare(strict_types=1);

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\BrowserSubscription;
use Cbox\Relay\Client\Data\Direction;
use Cbox\Relay\Client\Data\EndpointChanges;
use Cbox\Relay\Client\Data\Envelope;
use Cbox\Relay\Client\Data\EventType;
use Cbox\Relay\Client\Data\InboundRouteType;
use Cbox\Relay\Client\Data\JudgeInput;
use Cbox\Relay\Client\Data\JudgeKind;
use Cbox\Relay\Client\Data\MailAddress;
use Cbox\Relay\Client\Data\MailAttachment;
use Cbox\Relay\Client\Data\NewEndpoint;
use Cbox\Relay\Client\Data\NewEvent;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Data\PushUrgency;
use Cbox\Relay\Client\Data\SignatureScheme;
use Cbox\Relay\Client\Data\SuppressionReason;
use Cbox\Relay\Client\Data\TemplateReference;
use Cbox\Relay\Client\Exceptions\RelayMalformedResponse;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Exceptions\RelayTooLarge;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const AT = '2026-11-12T10:00:00Z';

function http(): RelayTransport
{
    return app(RelayTransport::class);
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function endpointBody(array $extra = []): array
{
    return [
        'id' => '01JBFENDPOINT', 'url' => 'https://customer.example/hooks', 'description' => null, 'event_types' => ['order.*'],
        'status' => 'active', 'disabled_reason' => null, 'envelope' => 'cbox', 'signature_scheme' => 'cbox', 'last_sequence' => 41,
        'health' => ['consecutive_failures' => 2, 'last_success_at' => AT, 'last_failure_at' => null, 'last_response_code' => 500, 'circuit_closes_at' => null],
        'created_at' => AT, ...$extra,
    ];
}

/**
 * @param  array<string, mixed>  $body
 */
function sentJson(string $method, string $path, array $body, ?string $project = 'ws_1'): void
{
    Http::assertSent(fn (Request $request): bool => $request->method() === $method
        && $request->url() === 'https://relay.test'.$path
        && $request->header('Relay-Project') === ($project === null ? [] : [$project])
        && json_decode($request->body(), true) === $body);
}

beforeEach(fn () => Http::preventStrayRequests());

it('declares and lists event types', function (): void {
    Http::fake(['relay.test/v1/event-types' => Http::response(['data' => [['type' => 'order.paid', 'category' => null, 'name' => 'Order paid', 'description' => null, 'example' => ['id' => 'o_1']]]])]);

    $declared = http()->putEventTypes([new EventType('order.paid', name: 'Order paid', example: ['id' => 'o_1'])], 'ws_1');
    $listed = http()->listEventTypes('ws_1');

    expect($declared[0]->type)->toBe('order.paid')
        ->and($declared[0]->example)->toBe(['id' => 'o_1'])
        ->and($listed)->toEqual($declared);

    sentJson('PUT', '/v1/event-types', ['types' => [['type' => 'order.paid', 'name' => 'Order paid', 'example' => ['id' => 'o_1']]]]);
});

it('publishes an event with its idempotency key in the body and an empty payload as an object', function (): void {
    Http::fake(['relay.test/v1/events' => Http::response(['id' => 'evt_1', 'deliveries' => 3, 'duplicate' => false], 202)]);

    $published = http()->publishEvent(new NewEvent('order.paid', [], 'order.paid:o_1', new DateTimeImmutable('2026-11-12T11:00:00+01:00')), 'ws_1');

    expect($published->id)->toBe('evt_1')->and($published->deliveries)->toBe(3)->and($published->duplicate)->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->body() === '{"type":"order.paid","payload":{},"idempotency_key":"order.paid:o_1","occurred_at":"2026-11-12T10:00:00Z"}'
        && ! $request->hasHeader('Idempotency-Key'));
});

it('publishes a batch and answers in order', function (): void {
    Http::fake(['relay.test/v1/events/batch' => Http::response(['data' => [['id' => 'evt_1', 'deliveries' => 1, 'duplicate' => false], ['id' => 'evt_2', 'deliveries' => 0, 'duplicate' => true]]], 202)]);

    $published = http()->publishEvents([new NewEvent('order.paid', ['id' => 'o_1']), new NewEvent('order.paid', ['id' => 'o_2'], 'k2')], 'ws_1');

    expect($published)->toHaveCount(2)->and($published[1]->duplicate)->toBeTrue();

    sentJson('POST', '/v1/events/batch', ['events' => [['type' => 'order.paid', 'payload' => ['id' => 'o_1']], ['type' => 'order.paid', 'payload' => ['id' => 'o_2'], 'idempotency_key' => 'k2']]]);
});

it('maps 413 to RelayTooLarge', function (): void {
    Http::fake(['relay.test/v1/events' => Http::response(['type' => 'about:blank', 'title' => 'Content Too Large', 'status' => 413], 413)]);

    http()->publishEvent(new NewEvent('order.paid', ['blob' => str_repeat('x', 10)]), 'ws_1');
})->throws(RelayTooLarge::class, 'Cbox Relay answered 413 Content Too Large.');

it('lists events and reads one with its payload and deliveries', function (): void {
    $summary = ['id' => 'evt_1', 'type' => 'order.paid', 'idempotency_key' => 'k1', 'occurred_at' => AT, 'created_at' => AT];
    $delivery = ['id' => 'whd_1', 'endpoint_id' => 'ep_1', 'event_id' => 'evt_1', 'type' => 'order.paid', 'sequence' => 7, 'status' => 'exhausted', 'attempts' => 12, 'last_response_code' => 500, 'last_error' => 'HTTP 500', 'next_attempt_at' => null, 'delivered_at' => null, 'created_at' => AT];

    Http::fake([
        'relay.test/v1/events?*' => Http::response(['data' => [$summary], 'next_cursor' => 'c2']),
        'relay.test/v1/events/evt_1' => Http::response([...$summary, 'payload' => ['id' => 'o_1'], 'deliveries' => [$delivery]]),
        'relay.test/v1/events/evt_2' => Http::response(['type' => 'https://cboxrelay.com/problems/event_not_found', 'title' => 'Not found', 'status' => 404], 404),
        'relay.test/v1/webhook-deliveries/whd_1/retry' => Http::response(['id' => 'whd_1', 'status' => 'pending'], 202),
    ]);

    $page = http()->listEvents('order.paid', 10, 'c1', 'ws_1');
    $event = http()->findEvent('evt_1', 'ws_1');

    expect($page->nextCursor)->toBe('c2')
        ->and($page->items[0]->payload)->toBeNull()
        ->and($page->items[0]->deliveries)->toBe([])
        ->and($event?->payload)->toBe(['id' => 'o_1'])
        ->and($event?->deliveries[0]->sequence)->toBe(7)
        ->and($event?->deliveries[0]->lastResponseCode)->toBe(500)
        ->and($event?->occurredAt?->format('c'))->toBe('2026-11-12T10:00:00+00:00')
        ->and(http()->findEvent('evt_2', 'ws_1'))->toBeNull()
        ->and(http()->retryWebhookDelivery('whd_1', 'ws_1')->status)->toBe('pending');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://relay.test/v1/events?type=order.paid&limit=10&cursor=c1');
});

it('does not hide a missing project behind a null from a find', function (): void {
    Http::fake(['relay.test/v1/events/evt_1' => Http::response(['type' => 'https://cboxrelay.com/problems/project_not_found', 'title' => 'Not found', 'status' => 404], 404)]);

    http()->findEvent('evt_1', 'ws_gone');
})->throws(RelayNotFound::class);

it('registers an endpoint and reads the secret', function (): void {
    Http::fake(['relay.test/v1/endpoints' => Http::response(endpointBody(['secret' => 's3cr3t']), 201)]);

    $endpoint = http()->createEndpoint(new NewEndpoint('https://customer.example/hooks', ['order.*'], 'Orders', Envelope::CloudEvents, SignatureScheme::StandardWebhooks), 'reg-1', 'ws_1');

    expect($endpoint->secret)->toBe('s3cr3t')
        ->and($endpoint->lastSequence)->toBe(41)
        ->and($endpoint->health->consecutiveFailures)->toBe(2)
        ->and($endpoint->health->lastResponseCode)->toBe(500)
        ->and($endpoint->subscribesTo('order.paid'))->toBeTrue()
        ->and($endpoint->subscribesTo('invoice.paid'))->toBeFalse();

    sentJson('POST', '/v1/endpoints', ['url' => 'https://customer.example/hooks', 'event_types' => ['order.*'], 'description' => 'Orders', 'envelope' => 'cloudevents', 'signature_scheme' => 'standard-webhooks']);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'reg-1'));
});

it('patches only what changed and can clear the description', function (): void {
    Http::fake(['relay.test/v1/endpoints/ep_1' => Http::response(endpointBody())]);

    http()->updateEndpoint('ep_1', new EndpointChanges(eventTypes: ['*'], clearDescription: true), 'ws_1');

    sentJson('PATCH', '/v1/endpoints/ep_1', ['event_types' => ['*'], 'description' => null]);
});

it('lists, finds, pauses, resumes, rotates, pings, replays and deletes endpoints', function (): void {
    Http::fake([
        'relay.test/v1/endpoints' => Http::response(['data' => [endpointBody()]]),
        'relay.test/v1/endpoints/ep_1' => Http::sequence()->push(endpointBody())->push('', 204),
        'relay.test/v1/endpoints/ep_1/pause' => Http::response(endpointBody(['status' => 'paused'])),
        'relay.test/v1/endpoints/ep_1/resume' => Http::response(endpointBody()),
        'relay.test/v1/endpoints/ep_1/rotate-secret' => Http::response(endpointBody(['secret' => 'new', 'previous_secret_expires_at' => '2026-11-13T10:00:00Z'])),
        'relay.test/v1/endpoints/ep_1/ping' => Http::response(['outcome' => 'delivered', 'response_code' => 200, 'duration_ms' => 87, 'error' => null]),
        'relay.test/v1/endpoints/ep_1/replay' => Http::response(['queued' => 14], 202),
    ]);

    expect(http()->listEndpoints('ws_1'))->toHaveCount(1)
        ->and(http()->findEndpoint('ep_1', 'ws_1')?->id)->toBe('01JBFENDPOINT')
        ->and(http()->pauseEndpoint('ep_1', 'ws_1')->status)->toBe('paused')
        ->and(http()->resumeEndpoint('ep_1', 'ws_1')->active())->toBeTrue()
        ->and(http()->rotateEndpointSecret('ep_1', 'ws_1')->previousSecretExpiresAt?->format('Y-m-d'))->toBe('2026-11-13')
        ->and(http()->pingEndpoint('ep_1', 'ws_1')->durationMs)->toBe(87)
        ->and(http()->replayEndpoint('ep_1', new DateTimeImmutable('2026-11-01T00:00:00+00:00'), null, ['order.paid'], 'ws_1'))->toBe(14);

    http()->deleteEndpoint('ep_1', 'ws_1');

    sentJson('POST', '/v1/endpoints/ep_1/replay', ['since' => '2026-11-01T00:00:00Z', 'types' => ['order.paid']]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://relay.test/v1/endpoints/ep_1');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pause') && $request->body() === '');
});

it('judges content and tells a new judgment from a known one', function (): void {
    $body = [
        'id' => 'jdg_1', 'kind' => 'template', 'direction' => 'outbound', 'template' => ['id' => 'welcome', 'version' => '3'], 'judged_by' => 'rules',
        'verdicts' => ['phishing' => 0, 'link_mismatch' => 0.6], 'category' => 'transactional', 'brand' => null,
        'findings' => [['verdict' => 'link_mismatch', 'layer' => 2, 'rule' => 'anchor_host_differs', 'score' => 0.6, 'detail' => 'acme.dk shown, evil.example linked']],
        'created_at' => AT,
    ];
    Http::fake([
        'relay.test/v1/judge' => Http::sequence()->push($body, 201)->push($body, 200),
        'relay.test/v1/judgments/jdg_1' => Http::response($body),
        'relay.test/v1/judgments/jdg_2' => Http::response(['type' => 'https://cboxrelay.com/problems/judgment_not_found', 'title' => 'Not found', 'status' => 404], 404),
    ]);

    $input = new JudgeInput('<p>Hi</p>', null, 'Welcome', new MailAddress('hello@acme.dk', 'Acme'), new TemplateReference('welcome', '3'), JudgeKind::Template, Direction::Outbound, ['X-Campaign' => 'a']);
    $judgment = http()->judge($input, 'j-1', 'ws_1');

    expect($judgment->created)->toBeTrue()
        ->and($judgment->score('link_mismatch'))->toBe(0.6)
        ->and($judgment->score('phishing'))->toBe(0.0)
        ->and($judgment->score('unknown'))->toBe(0.0)
        ->and($judgment->highest())->toBe(0.6)
        ->and($judgment->category)->toBe('transactional')
        ->and($judgment->template?->version)->toBe('3')
        ->and($judgment->findings[0]->layer)->toBe(2)
        ->and($judgment->findings[0]->rule)->toBe('anchor_host_differs')
        ->and(http()->judge($input, null, 'ws_1')->created)->toBeFalse()
        ->and(http()->findJudgment('jdg_1', 'ws_1')?->id)->toBe('jdg_1')
        ->and(http()->findJudgment('jdg_2', 'ws_1'))->toBeNull();

    sentJson('POST', '/v1/judge', [
        'kind' => 'template', 'direction' => 'outbound', 'from' => ['address' => 'hello@acme.dk', 'name' => 'Acme'], 'subject' => 'Welcome',
        'html' => '<p>Hi</p>', 'headers' => ['X-Campaign' => 'a'], 'template' => ['id' => 'welcome', 'version' => '3'],
    ]);
});

it('claims, verifies, lists and removes inbound routes', function (): void {
    $route = ['id' => 'irt_1', 'type' => 'domain', 'domain' => 'acme.dk', 'prefix' => null, 'pattern' => '*@acme.dk', 'status' => 'pending', 'verification' => ['type' => 'TXT', 'name' => '_relay.acme.dk', 'value' => 'relay=abc'], 'verified_at' => null, 'created_at' => AT];
    Http::fake([
        'relay.test/v1/inbound/routes' => Http::sequence()->push($route, 201)->push(['data' => [$route]]),
        'relay.test/v1/inbound/routes/irt_1/verify' => Http::response([...$route, 'status' => 'active', 'verified_at' => AT]),
        'relay.test/v1/inbound/routes/irt_1' => Http::response('', 204),
    ]);

    $created = http()->createInboundRoute(InboundRouteType::Domain, 'acme.dk', null, 'r-1', 'ws_1');

    expect($created->active())->toBeFalse()
        ->and($created->verification?->name)->toBe('_relay.acme.dk')
        ->and($created->verification?->required)->toBeTrue()
        ->and(http()->listInboundRoutes('ws_1'))->toHaveCount(1)
        ->and(http()->verifyInboundRoute('irt_1', 'ws_1')->active())->toBeTrue();

    http()->deleteInboundRoute('irt_1', 'ws_1');

    sentJson('POST', '/v1/inbound/routes', ['type' => 'domain', 'domain' => 'acme.dk']);
});

it('reads received mail as summaries and one mail with content and attachment links', function (): void {
    $summary = [
        'id' => 'inb_1', 'project_id' => 'prj_1', 'status' => 'parsed', 'kind' => 'mail', 'recipient' => 'hello@acme.dk', 'envelope_from' => 'ann@example.com',
        'from' => ['address' => 'ann@example.com', 'name' => 'Ann'], 'subject' => 'Hi', 'message_id' => '<1@example.com>', 'in_reply_to' => null, 'size' => 1200,
        'authentication' => ['spf' => 'pass', 'dkim' => 'pass'],
        'attachments' => [['id' => 'att_1', 'name' => 'a.pdf', 'type' => 'application/pdf', 'size' => 900, 'inline' => false, 'content_id' => null, 'url' => null]],
        'received_at' => AT,
    ];
    $full = [...$summary, 'text' => 'Hi', 'html' => '<p>Hi</p>', 'headers' => ['subject' => 'Hi'], 'attachments' => [[...$summary['attachments'][0], 'url' => 'https://files.example/a']]];

    Http::fake([
        'relay.test/v1/inbound?*' => Http::response(['data' => [$summary], 'next_cursor' => null]),
        'relay.test/v1/inbound/inb_1' => Http::sequence()->push($full)->push('', 204),
        'relay.test/v1/inbound/inb_2' => Http::response(['type' => 'https://cboxrelay.com/problems/inbound_not_found', 'title' => 'Not found', 'status' => 404], 404),
    ]);

    $listed = http()->listInbound(5)->items[0];
    $mail = http()->findInbound('inb_1');

    expect($listed->text)->toBeNull()
        ->and($listed->attachments[0]->url)->toBeNull()
        ->and($listed->from?->name)->toBe('Ann')
        ->and($listed->authentication)->toBe(['spf' => 'pass', 'dkim' => 'pass'])
        ->and($mail?->html)->toBe('<p>Hi</p>')
        ->and($mail?->headers)->toBe(['subject' => 'Hi'])
        ->and($mail?->attachments[0]->url)->toBe('https://files.example/a')
        ->and($mail?->parsed())->toBeTrue()
        ->and(http()->findInbound('inb_2'))->toBeNull();

    http()->deleteInbound('inb_1', 'erase-1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->hasHeader('Idempotency-Key', 'erase-1') && ! $request->hasHeader('Relay-Project'));
});

it('reads a received mail whose kind and sender are not known yet', function (): void {
    Http::fake(['relay.test/v1/inbound/inb_1' => Http::response([
        'id' => 'inb_1', 'project_id' => 'prj_1', 'status' => 'received', 'kind' => null, 'recipient' => 'hello@acme.dk', 'envelope_from' => '',
        'from' => null, 'subject' => null, 'message_id' => null, 'in_reply_to' => null, 'size' => 10, 'authentication' => [], 'attachments' => [], 'received_at' => AT,
    ])]);

    $mail = http()->findInbound('inb_1');

    expect($mail?->kind)->toBeNull()->and($mail?->from)->toBeNull()->and($mail?->parsed())->toBeFalse()->and($mail?->envelopeFrom)->toBe('');
});

it('reads the push key and manages subscriptions', function (): void {
    $subscription = ['id' => 'psb_1', 'ref' => 'contact-9', 'service' => 'fcm.googleapis.com', 'last_delivered_at' => null, 'created_at' => AT];
    Http::fake([
        'relay.test/v1/push/key' => Http::response(['public_key' => 'BPub']),
        'relay.test/v1/push/subscriptions' => Http::sequence()->push($subscription, 201)->push($subscription, 200),
        'relay.test/v1/push/subscriptions?ref=contact-9' => Http::response(['data' => [$subscription]]),
        'relay.test/v1/push/subscriptions/psb_1' => Http::response('', 204),
    ]);

    $browser = BrowserSubscription::fromArray(['endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'expirationTime' => null, 'keys' => ['p256dh' => 'P', 'auth' => 'A']]);

    expect(http()->pushKey('ws_1'))->toBe('BPub')
        ->and(http()->createPushSubscription($browser, 'contact-9', null, 'ws_1')->created)->toBeTrue()
        ->and(http()->createPushSubscription($browser, 'contact-9', null, 'ws_1')->created)->toBeFalse()
        ->and(http()->listPushSubscriptions('contact-9', 'ws_1')[0]->service)->toBe('fcm.googleapis.com');

    http()->deletePushSubscription('psb_1', 'ws_1');

    sentJson('POST', '/v1/push/subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'keys' => ['p256dh' => 'P', 'auth' => 'A'], 'ref' => 'contact-9']);
});

it('sends a push message and reads what became of it', function (): void {
    $message = ['id' => 'psm_1', 'subscription_id' => 'psb_1', 'status' => 'queued', 'attempts' => 0, 'response_code' => null, 'error' => null, 'created_at' => AT, 'finished_at' => null];
    Http::fake([
        'relay.test/v1/push' => Http::response($message, 202),
        'relay.test/v1/push/psm_1' => Http::response([...$message, 'status' => 'delivered', 'attempts' => 1, 'response_code' => 201, 'finished_at' => AT]),
    ]);

    expect(http()->sendPush('psb_1', ['title' => 'Shipped'], 3600, PushUrgency::High, 'orders', 'p-1', 'ws_1')->status)->toBe('queued')
        ->and(http()->findPush('psm_1', 'ws_1')?->responseCode)->toBe(201);

    sentJson('POST', '/v1/push', ['subscription' => 'psb_1', 'payload' => ['title' => 'Shipped'], 'ttl' => 3600, 'urgency' => 'high', 'topic' => 'orders']);

    http()->sendPush('psb_1', [], project: 'ws_1');
    http()->sendPush('psb_1', 'plain text', project: 'ws_1');

    Http::assertSent(fn (Request $request): bool => $request->body() === '{"subscription":"psb_1","payload":{}}');
    Http::assertSent(fn (Request $request): bool => $request->body() === '{"subscription":"psb_1","payload":"plain text"}');
});

it('registers, verifies and removes sending domains', function (): void {
    $domain = ['id' => 'sdm_1', 'domain' => 'acme.dk', 'status' => 'pending', 'records' => [
        ['purpose' => 'dkim', 'type' => 'TXT', 'name' => 'r1._domainkey.acme.dk', 'value' => 'v=DKIM1; p=…', 'required' => true],
        ['purpose' => 'spf', 'type' => 'TXT', 'name' => 'acme.dk', 'value' => 'v=spf1 …', 'required' => false],
    ], 'verified_at' => null, 'checked_at' => null, 'created_at' => AT];
    Http::fake([
        'relay.test/v1/domains' => Http::sequence()->push($domain, 201)->push(['data' => [$domain]]),
        'relay.test/v1/domains/sdm_1' => Http::sequence()->push($domain)->push('', 204),
        'relay.test/v1/domains/sdm_1/verify' => Http::response([...$domain, 'status' => 'verified', 'verified_at' => AT, 'checked_at' => AT]),
    ]);

    $created = http()->createDomain('acme.dk', 'd-1', 'ws_1');

    expect($created->verified())->toBeFalse()
        ->and($created->records[0]->purpose)->toBe('dkim')
        ->and($created->records[0]->required)->toBeTrue()
        ->and($created->records[1]->required)->toBeFalse()
        ->and(http()->listDomains('ws_1'))->toHaveCount(1)
        ->and(http()->findDomain('sdm_1', 'ws_1')?->domain)->toBe('acme.dk')
        ->and(http()->verifyDomain('sdm_1', 'ws_1')->verified())->toBeTrue();

    http()->deleteDomain('sdm_1', 'ws_1');

    sentJson('POST', '/v1/domains', ['domain' => 'acme.dk']);
});

it('adds, lists and removes suppressions', function (): void {
    $suppression = ['address' => 'ann+x@example.com', 'reason' => 'manual', 'source' => null, 'created_at' => AT];
    Http::fake([
        'relay.test/v1/suppressions' => Http::sequence()->push($suppression, 201)->push($suppression, 200),
        'relay.test/v1/suppressions?*' => Http::response(['data' => [[...$suppression, 'reason' => 'bounce', 'source' => 'msg_1']], 'next_cursor' => null]),
        'relay.test/v1/suppressions/*' => Http::response('', 204),
    ]);

    expect(http()->createSuppression('ann+x@example.com', SuppressionReason::Manual, null, 'ws_1')->created)->toBeTrue()
        ->and(http()->createSuppression('ann+x@example.com', null, null, 'ws_1')->created)->toBeFalse()
        ->and(http()->listSuppressions(10, null, 'ws_1')->items[0]->source)->toBe('msg_1');

    http()->deleteSuppression('ann+x@example.com', 'ws_1');

    sentJson('POST', '/v1/suppressions', ['address' => 'ann+x@example.com', 'reason' => 'manual']);
    sentJson('POST', '/v1/suppressions', ['address' => 'ann+x@example.com']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://relay.test/v1/suppressions/ann%2Bx%40example.com');
});

it('sends a mail and reads what became of it', function (): void {
    $mail = ['id' => 'msg_1', 'status' => 'queued', 'from' => 'hello@acme.dk', 'subject' => 'Your order', 'reason' => null, 'verdicts' => ['phishing' => 0],
        'recipients' => [['address' => 'ann@example.com', 'type' => 'to', 'status' => 'queued', 'detail' => null], ['address' => 'bob@example.com', 'type' => 'bcc', 'status' => 'suppressed', 'detail' => 'On the list.']],
        'created_at' => AT, 'sent_at' => null];
    Http::fake([
        'relay.test/v1/mail' => Http::response($mail, 202),
        'relay.test/v1/mail/msg_1' => Http::response([...$mail, 'status' => 'sent', 'sent_at' => AT]),
    ]);

    $sent = http()->sendMail(new OutgoingMail(
        from: new MailAddress('hello@acme.dk', 'Acme'),
        to: ['ann@example.com'],
        subject: 'Your order',
        html: '<p>Thanks</p>',
        text: 'Thanks',
        bcc: ['bob@example.com'],
        replyTo: 'support@acme.dk',
        headers: ['X-Order' => 'o_1'],
        attachments: [new MailAttachment('receipt.txt', 'text/plain', 'paid')],
    ), 'mail-1', 'ws_1');

    expect($sent->accepted())->toBeTrue()
        ->and($sent->recipients[1]->status)->toBe('suppressed')
        ->and($sent->verdicts)->toBe(['phishing' => 0.0])
        ->and(http()->findMail('msg_1', 'ws_1')?->sentAt)->not->toBeNull();

    sentJson('POST', '/v1/mail', [
        'from' => ['address' => 'hello@acme.dk', 'name' => 'Acme'], 'to' => ['ann@example.com'], 'bcc' => ['bob@example.com'], 'reply_to' => 'support@acme.dk',
        'subject' => 'Your order', 'html' => '<p>Thanks</p>', 'text' => 'Thanks', 'headers' => ['X-Order' => 'o_1'],
        'attachments' => [['name' => 'receipt.txt', 'type' => 'text/plain', 'content' => 'cGFpZA==']],
    ]);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'mail-1'));
});

it('refuses bodies it cannot read on the new endpoints too', function (Closure $call, string $url, mixed $body): void {
    Http::fake([$url => Http::response($body, 200)]);

    expect($call)->toThrow(RelayMalformedResponse::class);
})->with([
    'a published without duplicate' => [fn () => http()->publishEvent(new NewEvent('a.b'), 'ws_1'), 'relay.test/v1/events', ['id' => 'evt_1', 'deliveries' => 1]],
    'endpoints without data' => [fn () => http()->listEndpoints('ws_1'), 'relay.test/v1/endpoints', ['items' => []]],
    'a push key that is not a string' => [fn () => http()->pushKey('ws_1'), 'relay.test/v1/push/key', ['public_key' => 12]],
    'verdicts that are not numbers' => [fn () => http()->findMail('msg_1', 'ws_1'), 'relay.test/v1/mail/msg_1', ['id' => 'msg_1', 'status' => 'sent', 'from' => 'a@b.dk', 'subject' => 'x', 'reason' => null, 'verdicts' => ['phishing' => 'high'], 'recipients' => [], 'created_at' => AT, 'sent_at' => null]],
]);
