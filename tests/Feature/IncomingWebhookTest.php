<?php

declare(strict_types=1);

use Cbox\Relay\Client\Contracts\WebhookSecrets;
use Cbox\Relay\Client\Data\Envelope;
use Cbox\Relay\Client\Exceptions\RelayWebhookRejected;
use Cbox\Relay\Client\Webhooks\Events\EndpointDisabled;
use Cbox\Relay\Client\Webhooks\Events\MailAccepted;
use Cbox\Relay\Client\Webhooks\Events\MailBlocked;
use Cbox\Relay\Client\Webhooks\Events\MailBounced;
use Cbox\Relay\Client\Webhooks\Events\MailComplained;
use Cbox\Relay\Client\Webhooks\Events\MailReceived;
use Cbox\Relay\Client\Webhooks\Events\PushDelivered;
use Cbox\Relay\Client\Webhooks\Events\PushExpired;
use Cbox\Relay\Client\Webhooks\Events\ScopeOutboundPaused;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;
use Cbox\Relay\Client\Webhooks\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
 * A vector from the reference implementation: this body, secret and timestamp were
 * signed by Cbox\WebhookSignature\Schemes\CboxScheme (cboxdk/laravel-webhook-signature),
 * the code Relay itself signs with. The header below is its output, not this package's.
 */
const VECTOR_BODY = '{"id":"01JBF0000000000000000000AA","type":"mail.bounced","created_at":"2026-11-12T10:00:00+00:00","delivery_id":"01JBF0000000000000000000BB","attempt":1,"sequence":41,"scope":{"partition":"org_1","key":"scp_1"},"api_version":null,"data":{"id":"msg_1","scope_id":"scp_1","recipient":"ann@example.com","permanent":true,"status":"5.1.1","diagnostic":"550 no such user"}}';
const VECTOR_SECRET = 'fake_secret_for_vectors';
const VECTOR_TIME = 1794477600;
const VECTOR_HEADER = 't=1794477600,v1=9a9244351ffbc4caeb0e551deec4bcc30449ef55e1c3e8e5c5633df7fba193ad';

function rejection(Closure $verify): string
{
    try {
        $verify();
    } catch (RelayWebhookRejected $e) {
        return $e->reason;
    }

    return 'accepted';
}

/**
 * @param  array<string, mixed>  $data
 */
function cboxEnvelope(string $type, array $data): string
{
    return (string) json_encode(['id' => 'evt_1', 'type' => $type, 'created_at' => '2026-11-12T10:00:00+00:00', 'delivery_id' => 'whd_1', 'attempt' => 2, 'sequence' => 7, 'scope' => ['partition' => 'org_1', 'key' => 'scp_1'], 'api_version' => null, 'data' => $data]);
}

it('verifies the reference implementation\'s signature', function (): void {
    expect(WebhookSignature::verify(VECTOR_BODY, VECTOR_HEADER, [VECTOR_SECRET], 300, VECTOR_TIME))->toBe(VECTOR_TIME)
        ->and(WebhookSignature::header(VECTOR_BODY, VECTOR_SECRET, VECTOR_TIME))->toBe(VECTOR_HEADER);
});

it('accepts either secret during a rotation', function (): void {
    expect(WebhookSignature::verify(VECTOR_BODY, VECTOR_HEADER, ['the_new_secret', VECTOR_SECRET], 300, VECTOR_TIME))->toBe(VECTOR_TIME)
        ->and(WebhookSignature::verify(VECTOR_BODY, VECTOR_HEADER, [VECTOR_SECRET, 'the_old_secret'], 300, VECTOR_TIME))->toBe(VECTOR_TIME);
});

it('refuses a delivery that does not prove itself', function (string $reason, string $body, ?string $header, array $secrets, int $now): void {
    expect(rejection(fn () => WebhookSignature::verify($body, $header, $secrets, 300, $now)))->toBe($reason);
})->with([
    'no secret' => [RelayWebhookRejected::REASON_NO_SECRET, VECTOR_BODY, VECTOR_HEADER, [], VECTOR_TIME],
    'only empty secrets' => [RelayWebhookRejected::REASON_NO_SECRET, VECTOR_BODY, VECTOR_HEADER, ['', ''], VECTOR_TIME],
    'no header' => [RelayWebhookRejected::REASON_MISSING_SIGNATURE, VECTOR_BODY, null, [VECTOR_SECRET], VECTOR_TIME],
    'an empty header' => [RelayWebhookRejected::REASON_MISSING_SIGNATURE, VECTOR_BODY, ' ', [VECTOR_SECRET], VECTOR_TIME],
    'no v1' => [RelayWebhookRejected::REASON_MALFORMED_SIGNATURE, VECTOR_BODY, 't=1794477600', [VECTOR_SECRET], VECTOR_TIME],
    'a v1 that is not a sha256 in hex' => [RelayWebhookRejected::REASON_MALFORMED_SIGNATURE, VECTOR_BODY, 't=1794477600,v1=abc', [VECTOR_SECRET], VECTOR_TIME],
    'no timestamp' => [RelayWebhookRejected::REASON_MISSING_TIMESTAMP, VECTOR_BODY, 'v1='.str_repeat('a', 64), [VECTOR_SECRET], VECTOR_TIME],
    'a timestamp that is not digits' => [RelayWebhookRejected::REASON_MALFORMED_TIMESTAMP, VECTOR_BODY, 't=1e9,v1='.str_repeat('a', 64), [VECTOR_SECRET], VECTOR_TIME],
    'too old' => [RelayWebhookRejected::REASON_STALE_TIMESTAMP, VECTOR_BODY, VECTOR_HEADER, [VECTOR_SECRET], VECTOR_TIME + 301],
    'from the future' => [RelayWebhookRejected::REASON_FUTURE_TIMESTAMP, VECTOR_BODY, VECTOR_HEADER, [VECTOR_SECRET], VECTOR_TIME - 301],
    'the wrong secret' => [RelayWebhookRejected::REASON_SIGNATURE_MISMATCH, VECTOR_BODY, VECTOR_HEADER, ['another_secret'], VECTOR_TIME],
    'a changed body' => [RelayWebhookRejected::REASON_SIGNATURE_MISMATCH, VECTOR_BODY.' ', VECTOR_HEADER, [VECTOR_SECRET], VECTOR_TIME],
    'a changed timestamp' => [RelayWebhookRejected::REASON_SIGNATURE_MISMATCH, VECTOR_BODY, str_replace('t=1794477600', 't=1794477601', VECTOR_HEADER), [VECTOR_SECRET], VECTOR_TIME],
]);

it('accepts the edges of the tolerance window', function (): void {
    expect(rejection(fn () => WebhookSignature::verify(VECTOR_BODY, VECTOR_HEADER, [VECTOR_SECRET], 300, VECTOR_TIME + 300)))->toBe('accepted')
        ->and(rejection(fn () => WebhookSignature::verify(VECTOR_BODY, VECTOR_HEADER, [VECTOR_SECRET], 300, VECTOR_TIME - 300)))->toBe('accepted');
});

it('reads the cbox envelope', function (): void {
    $webhook = IncomingWebhook::parse(VECTOR_BODY);

    expect($webhook->envelope)->toBe(Envelope::Cbox)
        ->and($webhook->id)->toBe('01JBF0000000000000000000AA')
        ->and($webhook->type)->toBe('mail.bounced')
        ->and($webhook->deliveryId)->toBe('01JBF0000000000000000000BB')
        ->and($webhook->attempt)->toBe(1)
        ->and($webhook->sequence)->toBe(41)
        ->and($webhook->scopeId)->toBe('scp_1')
        ->and($webhook->apiVersion)->toBeNull()
        ->and($webhook->occurredAt?->format('c'))->toBe('2026-11-12T10:00:00+00:00')
        ->and($webhook->fromRelay())->toBeTrue();

    $event = $webhook->event();

    expect($event)->toBeInstanceOf(MailBounced::class)
        ->and($event->id)->toBe('msg_1')
        ->and($event->recipient)->toBe('ann@example.com')
        ->and($event->permanent)->toBeTrue()
        ->and($event->status)->toBe('5.1.1')
        ->and($event->diagnostic)->toBe('550 no such user');
});

it('reads the CloudEvents envelope into the same thing', function (): void {
    $webhook = IncomingWebhook::parse((string) json_encode([
        'specversion' => '1.0', 'id' => 'evt_1', 'source' => 'https://api.cboxrelay.com', 'type' => 'push.expired', 'time' => '2026-11-12T10:00:00Z',
        'subject' => 'org_1/scp_1', 'datacontenttype' => 'application/json', 'deliveryid' => 'whd_1', 'sequence' => '41', 'sequencetype' => 'Integer', 'attempt' => 3,
        'data' => ['id' => 'psm_1', 'scope_id' => 'scp_1', 'subscription_id' => 'psb_1', 'external_ref' => 'contact-9', 'attempts' => 1],
    ]));

    expect($webhook->envelope)->toBe(Envelope::CloudEvents)
        ->and($webhook->id)->toBe('evt_1')
        ->and($webhook->deliveryId)->toBe('whd_1')
        ->and($webhook->sequence)->toBe(41)
        ->and($webhook->attempt)->toBe(3)
        ->and($webhook->scopeId)->toBe('scp_1')
        ->and($webhook->occurredAt?->getTimestamp())->toBe(1794477600)
        ->and($webhook->event())->toBeInstanceOf(PushExpired::class)
        ->and($webhook->event()->subscriptionId)->toBe('psb_1')
        ->and($webhook->event()->externalRef)->toBe('contact-9');
});

it('reads every one of Relay\'s own events', function (string $type, array $data, string $class, Closure $check): void {
    $event = IncomingWebhook::parse(cboxEnvelope($type, $data))->event();

    expect($event)->toBeInstanceOf($class)->and($class::type())->toBe($type)->and($check($event))->toBeTrue();
})->with([
    'mail.received' => ['mail.received', ['id' => 'inb_1', 'scope_id' => 'scp_1', 'recipient' => 'hello@acme.dk', 'envelope_from' => 'ann@example.com', 'from' => 'ann@example.com', 'subject' => 'Hi', 'kind' => 'auto_reply', 'auto_reply' => true, 'attachments' => 2, 'size' => 1200, 'received_at' => '2026-11-12T10:00:00Z'],
        MailReceived::class, fn (MailReceived $e): bool => $e->id === 'inb_1' && $e->autoReply && $e->kind === 'auto_reply' && $e->attachments === 2 && $e->size === 1200 && $e->from === 'ann@example.com' && $e->receivedAt?->getTimestamp() === 1794477600],
    'mail.accepted' => ['mail.accepted', ['id' => 'msg_1', 'scope_id' => 'scp_1', 'from' => 'hello@acme.dk', 'recipients' => 3, 'suppressed' => 1, 'status' => 'queued', 'reason' => null],
        MailAccepted::class, fn (MailAccepted $e): bool => $e->recipients === 3 && $e->suppressed === 1 && $e->status === 'queued' && $e->reason === null],
    'mail.blocked' => ['mail.blocked', ['id' => 'msg_1', 'scope_id' => 'scp_1', 'from' => 'hello@acme.dk', 'recipients' => 1, 'suppressed' => 1, 'status' => 'blocked', 'reason' => 'Every recipient is suppressed.'],
        MailBlocked::class, fn (MailBlocked $e): bool => $e->status === 'blocked' && $e->reason === 'Every recipient is suppressed.'],
    'mail.complained' => ['mail.complained', ['id' => 'msg_1', 'scope_id' => 'scp_1', 'recipient' => 'ann@example.com'],
        MailComplained::class, fn (MailComplained $e): bool => $e->recipient === 'ann@example.com'],
    'push.delivered' => ['push.delivered', ['id' => 'psm_1', 'scope_id' => 'scp_1', 'subscription_id' => 'psb_1', 'external_ref' => null, 'attempts' => 2],
        PushDelivered::class, fn (PushDelivered $e): bool => $e->subscriptionId === 'psb_1' && $e->externalRef === null && $e->attempts === 2],
    'endpoint.disabled' => ['endpoint.disabled', ['endpoint_id' => 'ep_1', 'scope_id' => 'scp_1', 'reason' => 'failing'],
        EndpointDisabled::class, fn (EndpointDisabled $e): bool => $e->endpointId === 'ep_1' && $e->reason === 'failing'],
    'scope.outbound_paused' => ['scope.outbound_paused', ['scope_id' => 'scp_1', 'reason' => 'bounce_rate', 'sent' => 400, 'bounced' => 30, 'complained' => 0],
        ScopeOutboundPaused::class, fn (ScopeOutboundPaused $e): bool => $e->reason === 'bounce_rate' && $e->sent === 400 && $e->bounced === 30 && $e->complained === 0],
]);

it('hands back a product\'s own event as its data', function (): void {
    $webhook = IncomingWebhook::parse(cboxEnvelope('order.paid', ['id' => 'o_1']));

    expect($webhook->fromRelay())->toBeFalse()->and($webhook->event())->toBeNull()->and($webhook->data)->toBe(['id' => 'o_1']);
});

it('refuses a body that is not an envelope', function (string $body): void {
    expect(rejection(fn () => IncomingWebhook::parse($body)))->toBe(RelayWebhookRejected::REASON_MALFORMED_BODY);
})->with([
    'not JSON' => ['<html>'],
    'a list' => ['[{"id":"a"}]'],
    'a scalar' => ['"hello"'],
    'no id' => ['{"type":"order.paid","data":{}}'],
    'no type' => ['{"id":"evt_1","data":{}}'],
]);

it('refuses one of Relay\'s events whose data is not what Relay sends', function (): void {
    expect(rejection(fn () => IncomingWebhook::parse(cboxEnvelope('mail.bounced', ['id' => 'msg_1']))->event()))->toBe(RelayWebhookRejected::REASON_MALFORMED_BODY);
});

describe('the relay.webhook middleware', function (): void {
    beforeEach(function (): void {
        config()->set('relay.webhook.secret', VECTOR_SECRET);

        Route::post('/hooks/relay', function (Request $request) {
            $webhook = IncomingWebhook::from($request);

            return response()->json(['type' => $webhook->type, 'event' => $webhook->event() === null ? null : $webhook->event()::class, 'scope' => $webhook->scopeId]);
        })->middleware('relay.webhook');

        Route::post('/hooks/open', fn (Request $request) => IncomingWebhook::from($request)->type);
    });

    $post = fn (string $body, ?string $header) => test()->call('POST', '/hooks/relay', server: array_filter(['HTTP_X_CBOX_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json']), content: $body);

    it('lets a signed delivery through and hands over the parsed webhook', function () use ($post): void {
        $this->travelTo(Carbon::createFromTimestamp(VECTOR_TIME + 10));

        $post(VECTOR_BODY, VECTOR_HEADER)->assertOk()->assertExactJson(['type' => 'mail.bounced', 'event' => MailBounced::class, 'scope' => 'scp_1']);
    });

    it('answers 401 to a bad, a missing and a stale signature', function () use ($post): void {
        $post(VECTOR_BODY, WebhookSignature::header(VECTOR_BODY, 'wrong'))->assertUnauthorized();
        $post(VECTOR_BODY, null)->assertUnauthorized();
        $post(VECTOR_BODY, VECTOR_HEADER)->assertUnauthorized();
        $post(VECTOR_BODY.' ', WebhookSignature::header(VECTOR_BODY, VECTOR_SECRET))->assertUnauthorized();
    });

    it('honours the configured tolerance', function () use ($post): void {
        config()->set('relay.webhook.tolerance', 30);

        $post(VECTOR_BODY, WebhookSignature::header(VECTOR_BODY, VECTOR_SECRET, time() - 20))->assertOk();
        $post(VECTOR_BODY, WebhookSignature::header(VECTOR_BODY, VECTOR_SECRET, time() - 40))->assertUnauthorized();
    });

    it('accepts the previous secret while a rotation is under way', function () use ($post): void {
        config()->set('relay.webhook.secret', 'the_new_secret');
        $post(VECTOR_BODY, WebhookSignature::header(VECTOR_BODY, VECTOR_SECRET))->assertUnauthorized();

        config()->set('relay.webhook.previous_secret', VECTOR_SECRET);
        $post(VECTOR_BODY, WebhookSignature::header(VECTOR_BODY, VECTOR_SECRET))->assertOk();
        $post(VECTOR_BODY, WebhookSignature::header(VECTOR_BODY, 'the_new_secret'))->assertOk();
    });

    it('answers 500, not 401, when no secret is configured', function () use ($post): void {
        config()->set('relay.webhook.secret', null);

        $post(VECTOR_BODY, WebhookSignature::header(VECTOR_BODY, VECTOR_SECRET))->assertStatus(500);
    });

    it('answers 400 to a signed body that is not an envelope', function () use ($post): void {
        $post('{"hello":"world"}', WebhookSignature::header('{"hello":"world"}', VECTOR_SECRET))->assertStatus(400);
    });

    it('takes the secrets from a bound resolver', function () use ($post): void {
        app()->bind(WebhookSecrets::class, fn () => new class implements WebhookSecrets
        {
            public function for(Request $request): array
            {
                return ['per_scope_secret'];
            }
        });

        $post(VECTOR_BODY, WebhookSignature::header(VECTOR_BODY, 'per_scope_secret'))->assertOk();
        $post(VECTOR_BODY, WebhookSignature::header(VECTOR_BODY, VECTOR_SECRET))->assertUnauthorized();
    });

    it('refuses to hand over a webhook on a route without the middleware', function (): void {
        $this->withoutExceptionHandling();

        expect(fn () => $this->postJson('/hooks/open', ['id' => 'evt_1', 'type' => 'order.paid']))->toThrow(RelayWebhookRejected::class);
    });
});
