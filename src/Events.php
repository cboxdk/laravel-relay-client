<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\DeliveryRetry;
use Cbox\Relay\Client\Data\Event;
use Cbox\Relay\Client\Data\Page;

/** What a scope published, and what became of each delivery. Reached through `Relay::events()`. */
class Events
{
    public function __construct(
        private readonly RelayTransport $transport,
        private readonly ActingScope $scope = new ActingScope,
    ) {}

    /** One event with its payload and its deliveries; null when there is none with that id. */
    public function find(string $id): ?Event
    {
        return $this->transport->findEvent($id, $this->scope->required());
    }

    /**
     * Summaries, newest first, without payloads or deliveries.
     *
     * @return Page<Event>
     */
    public function list(?string $type = null, ?int $limit = null, ?string $cursor = null): Page
    {
        return $this->transport->listEvents($type, $limit, $cursor, $this->scope->required());
    }

    /** Queue a delivery that failed for good again. `$deliveryId` is a `WebhookDelivery::$id`. */
    public function retryDelivery(string $deliveryId): DeliveryRetry
    {
        return $this->transport->retryWebhookDelivery($deliveryId, $this->scope->required());
    }
}
