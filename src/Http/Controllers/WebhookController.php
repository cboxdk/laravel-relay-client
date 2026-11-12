<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Http\Controllers;

use Cbox\Relay\Client\Contracts\MessageStore;
use Cbox\Relay\Client\Exceptions\RelayWebhookRejected;
use Cbox\Relay\Client\RelayConfig;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;
use Cbox\Relay\Client\Webhooks\ReceivedWebhookFactory;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The package's own webhook endpoint (`Relay::webhookRoutes()` or `relay.webhook.route`).
 * The `relay.webhook` middleware has verified the signature before this runs.
 *
 * One delivery is handled once: its delivery id is remembered in the cache, and a
 * delivery seen before answers 200 without dispatching again. The answer is 204 only
 * after the store is written and every synchronous listener has returned. A listener that
 * throws makes this answer 500 and forgets the delivery, so Relay's retry is handled as
 * new. Listeners that implement `ShouldQueue` have only been put on the queue by then:
 * whether they succeed is the queue's business, not Relay's.
 */
class WebhookController
{
    /** Seconds a delivery counts as "being handled right now" by another request. */
    private const int HANDLING_SECONDS = 60;

    public function __construct(
        private readonly RelayConfig $config,
        private readonly CacheFactory $cache,
        private readonly Dispatcher $events,
        private readonly MessageStore $store,
        private readonly ReceivedWebhookFactory $received,
    ) {}

    public function __invoke(Request $request): Response
    {
        $webhook = IncomingWebhook::from($request);
        $connection = $request->route('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        try {
            $event = $this->received->make($webhook, $connection);
        } catch (RelayWebhookRejected) {
            return new Response('Unreadable webhook body.', Response::HTTP_BAD_REQUEST);
        }

        $cache = $this->cache->store($this->config->webhookCacheStore());
        $key = 'relay:webhook:'.($connection ?? RelayConfig::DEFAULT_CONNECTION).':'.($webhook->deliveryId ?? $webhook->id);

        if ($cache->get($key) === 'done') {
            return new Response('Already handled.', Response::HTTP_OK);
        }

        if (! $cache->add($key, 'handling', self::HANDLING_SECONDS)) {
            // The same delivery is in another request right now. Not a success yet: Relay tries again.
            return new Response('This delivery is being handled.', Response::HTTP_CONFLICT);
        }

        try {
            $this->store->recordEvent($webhook, $connection);
            $this->events->dispatch($event);
        } catch (Throwable $e) {
            $cache->forget($key);

            throw $e;
        }

        $cache->put($key, 'done', $this->config->webhookDedupeSeconds());

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
