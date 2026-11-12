<?php

declare(strict_types=1);

use Cbox\Relay\Client\Contracts\MessageStore;
use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\RelayServiceProvider;
use Cbox\Relay\Client\Testing\FakeMessageStore;
use Cbox\Relay\Client\Webhooks\Events\MailBounced as MailBouncedData;
use Cbox\Relay\Client\Webhooks\Received\MailBounced;
use Cbox\Relay\Client\Webhooks\Received\MailDelivered;
use Cbox\Relay\Client\Webhooks\Received\OtherEventReceived;
use Cbox\Relay\Client\Webhooks\WebhookSignature;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * @param  array<string, mixed>  $data
 */
function relayDelivery(string $type, array $data, string $deliveryId = 'whd_1'): string
{
    return (string) json_encode(['id' => 'evt_'.$deliveryId, 'type' => $type, 'created_at' => '2026-11-12T10:00:00+00:00', 'delivery_id' => $deliveryId, 'attempt' => 1, 'sequence' => 1, 'scope' => ['partition' => 'org_1', 'key' => 'prj_1'], 'api_version' => null, 'data' => $data]);
}

function postRelayWebhook(string $body, string $secret = 'whsec_default', string $path = '/relay/webhook'): TestResponse
{
    return test()->call('POST', $path, server: ['HTTP_X_CBOX_SIGNATURE' => WebhookSignature::header($body, $secret), 'CONTENT_TYPE' => 'application/json'], content: $body);
}

beforeEach(function (): void {
    config()->set('relay.webhook.secret', 'whsec_default');
    config()->set('relay.connections.billing', ['key' => 'rlk_test_billing', 'webhook' => ['secret' => 'whsec_billing']]);
    Relay::webhookRoutes();
    app('router')->getRoutes()->refreshNameLookups();
});

it('registers nothing until it is asked to', function (): void {
    $router = new Router(app('events'), app());

    expect($router->getRoutes()->getByName('relay.webhook'))->toBeNull()
        ->and(app('router')->getRoutes()->getByName('relay.webhook')?->uri())->toBe('relay/webhook')
        ->and(app('router')->getRoutes()->getByName('relay.webhook.connection')?->uri())->toBe('relay/webhook/{connection}')
        // Only the verifying middleware: no session, no CSRF check before the signature.
        ->and(app('router')->getRoutes()->getByName('relay.webhook')?->gatherMiddleware())->toBe(['relay.webhook']);
});

it('dispatches one typed Laravel event per delivery, and the same delivery once', function (): void {
    Event::fake([MailBounced::class, MailDelivered::class, OtherEventReceived::class]);
    $body = relayDelivery('mail.bounced', ['id' => 'msg_1', 'project_id' => 'prj_1', 'recipient' => 'ann@example.com', 'permanent' => true, 'status' => '5.1.1', 'diagnostic' => 'No such user']);

    postRelayWebhook($body)->assertNoContent();
    // Relay's retry of the same delivery: accepted, not handled again.
    postRelayWebhook($body)->assertOk()->assertSee('Already handled.');

    Event::assertDispatchedTimes(MailBounced::class, 1);
    Event::assertDispatched(MailBounced::class, fn (MailBounced $event): bool => $event->event instanceof MailBouncedData
        && $event->event->recipient === 'ann@example.com'
        && $event->webhook()->deliveryId === 'whd_1'
        && $event->connection() === null
        && $event instanceof ReceivedWebhook);

    postRelayWebhook(relayDelivery('mail.delivered', ['id' => 'msg_1', 'project_id' => 'prj_1', 'recipient' => 'bo@example.com'], 'whd_2'))->assertNoContent();
    Event::assertDispatchedTimes(MailDelivered::class, 1);
});

it('dispatches a catch-all event for a type that is not one of Relay\'s own', function (): void {
    Event::fake([OtherEventReceived::class]);

    postRelayWebhook(relayDelivery('order.paid', ['order' => 7]))->assertNoContent();

    Event::assertDispatched(OtherEventReceived::class, fn (OtherEventReceived $event): bool => $event->webhook->type === 'order.paid' && $event->webhook->data === ['order' => 7]);
});

it('verifies a named connection\'s deliveries with that connection\'s secret', function (): void {
    Event::fake([MailDelivered::class]);
    $body = relayDelivery('mail.delivered', ['id' => 'msg_9', 'project_id' => 'prj_1', 'recipient' => 'ann@example.com']);

    postRelayWebhook($body, 'whsec_default', '/relay/webhook/billing')->assertUnauthorized();
    postRelayWebhook($body, 'whsec_billing', '/relay/webhook/unknown')->assertStatus(500);
    postRelayWebhook($body, 'whsec_billing', '/relay/webhook/billing')->assertNoContent();
    // The same delivery id on another connection is another delivery.
    postRelayWebhook($body)->assertNoContent();

    Event::assertDispatchedTimes(MailDelivered::class, 2);
    Event::assertDispatched(MailDelivered::class, fn (MailDelivered $event): bool => $event->connection === 'billing');
});

it('answers 500 and forgets the delivery when a listener fails, so the retry is handled', function (): void {
    $calls = 0;
    Event::listen(MailDelivered::class, function () use (&$calls): void {
        if (++$calls === 1) {
            throw new RuntimeException('The database is away.');
        }
    });
    $body = relayDelivery('mail.delivered', ['id' => 'msg_1', 'project_id' => 'prj_1', 'recipient' => 'ann@example.com']);

    $this->withoutExceptionHandling();
    expect(fn () => postRelayWebhook($body))->toThrow(RuntimeException::class);
    $this->withExceptionHandling();

    postRelayWebhook($body)->assertNoContent();
    postRelayWebhook($body)->assertOk();

    expect($calls)->toBe(2);
});

it('refuses an unsigned delivery and one of Relay\'s events with the wrong data', function (): void {
    Event::fake([MailBounced::class, MailDelivered::class, OtherEventReceived::class]);

    test()->call('POST', '/relay/webhook', content: relayDelivery('mail.delivered', ['id' => 'msg_1']))->assertUnauthorized();
    postRelayWebhook(relayDelivery('mail.bounced', ['id' => 'msg_1']))->assertStatus(400);

    Event::assertNotDispatched(MailBounced::class);
    Event::assertNotDispatched(MailDelivered::class);
    Event::assertNotDispatched(OtherEventReceived::class);
});

it('hands every mail event to the store before the listeners hear it', function (): void {
    $store = new FakeMessageStore;
    $this->app->instance(MessageStore::class, $store);
    Event::fake([MailDelivered::class]);
    $body = relayDelivery('mail.delivered', ['id' => 'msg_1', 'project_id' => 'prj_1', 'recipient' => 'ann@example.com']);

    postRelayWebhook($body)->assertNoContent();
    postRelayWebhook($body)->assertOk();

    $store->assertEventKept('mail.delivered');
});

it('registers the route from config when a path is set', function (): void {
    config()->set('relay.webhook.route', 'hooks/from-relay');
    (new RelayServiceProvider($this->app))->boot();

    expect(collect(app('router')->getRoutes()->getRoutes())->map->uri()->all())->toContain('hooks/from-relay', 'hooks/from-relay/{connection}');
});
