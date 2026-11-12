<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing\Concerns;

use Cbox\Relay\Client\Data\DeliveryRetry;
use Cbox\Relay\Client\Data\Endpoint;
use Cbox\Relay\Client\Data\EndpointChanges;
use Cbox\Relay\Client\Data\EndpointHealth;
use Cbox\Relay\Client\Data\Event;
use Cbox\Relay\Client\Data\EventType;
use Cbox\Relay\Client\Data\NewEndpoint;
use Cbox\Relay\Client\Data\NewEvent;
use Cbox\Relay\Client\Data\Page;
use Cbox\Relay\Client\Data\PingResult;
use Cbox\Relay\Client\Data\Published;
use Cbox\Relay\Client\Data\WebhookDelivery;
use Cbox\Relay\Client\Testing\Operation;
use Cbox\Relay\Client\Testing\RecordedCall;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;
use DateTimeInterface;
use PHPUnit\Framework\Assert;

/**
 * Webhooks out, in memory: event types, endpoints, published events and one delivery
 * per subscribed endpoint. Nothing is ever sent to an endpoint.
 */
trait FakesWebhooks
{
    /** @var array<string, list<EventType>> by scope */
    private array $eventTypes = [];

    /** @var array<string, array<string, Endpoint>> by scope, then id */
    private array $endpoints = [];

    /** @var array<string, array<string, Event>> by scope, then id; each with its payload */
    private array $events = [];

    /** @var array<string, array<string, WebhookDelivery>> by scope, then id */
    private array $webhookDeliveries = [];

    /** @var list<array{event: NewEvent, scope: string|null, id: string}> every event that was really published */
    private array $published = [];

    private PingResult $pingResult;

    public function listEventTypes(?string $scope = null): array
    {
        $this->record(new RecordedCall(Operation::ListEventTypes, $scope));

        return $this->eventTypes[$this->bucket($scope)] ?? [];
    }

    public function putEventTypes(array $types, ?string $scope = null): array
    {
        $this->record(new RecordedCall(Operation::PutEventTypes, $scope));
        $bucket = $this->bucket($scope);

        foreach ($types as $type) {
            if (isset(IncomingWebhook::EVENTS[$type->type])) {
                throw $this->invalid('types', "The type '{$type->type}' is one of Relay's own events and cannot be declared.");
            }
        }

        return $this->eventTypes[$bucket] = $types;
    }

    public function publishEvent(NewEvent $event, ?string $scope = null): Published
    {
        $this->record(new RecordedCall(Operation::PublishEvent, $scope, idempotencyKey: $event->idempotencyKey, subject: $event));
        $bucket = $this->bucket($scope);
        $this->guardDeclared($bucket, $event);

        return $this->store($bucket, $event, $scope);
    }

    public function publishEvents(array $events, ?string $scope = null): array
    {
        $this->record(new RecordedCall(Operation::PublishEvents, $scope));
        $bucket = $this->bucket($scope);

        if ($events === [] || count($events) > 100) {
            throw $this->invalid('events', 'A batch holds 1 to 100 events.');
        }

        // All or none: every event is checked before the first is stored.
        foreach ($events as $event) {
            $this->guardDeclared($bucket, $event);
        }

        return array_map(fn (NewEvent $event): Published => $this->store($bucket, $event, $scope), $events);
    }

    public function listEvents(?string $type = null, ?int $limit = null, ?string $cursor = null, ?string $scope = null): Page
    {
        $this->record(new RecordedCall(Operation::ListEvents, $scope, $type, limit: $limit, cursor: $cursor));

        $events = array_values(array_filter(
            array_reverse(array_values($this->events[$this->bucket($scope)] ?? [])),
            static fn (Event $event): bool => $type === null || $type === '' || $event->type === $type,
        ));

        // Summaries: no payload, no deliveries.
        return $this->page(array_map(
            static fn (Event $event): Event => new Event($event->id, $event->type, $event->idempotencyKey, $event->occurredAt, $event->createdAt),
            $events,
        ), $limit, $cursor);
    }

    public function findEvent(string $id, ?string $scope = null): ?Event
    {
        $this->record(new RecordedCall(Operation::FindEvent, $scope, $id));
        $bucket = $this->bucket($scope);
        $event = $this->events[$bucket][$id] ?? null;

        return $event === null ? null : new Event(
            $event->id,
            $event->type,
            $event->idempotencyKey,
            $event->occurredAt,
            $event->createdAt,
            $event->payload,
            array_values(array_filter($this->webhookDeliveries[$bucket] ?? [], static fn (WebhookDelivery $delivery): bool => $delivery->eventId === $id)),
        );
    }

    public function retryWebhookDelivery(string $id, ?string $scope = null): DeliveryRetry
    {
        $this->record(new RecordedCall(Operation::RetryWebhookDelivery, $scope, $id));
        $bucket = $this->bucket($scope);

        if (! isset($this->webhookDeliveries[$bucket][$id])) {
            throw $this->missing('delivery_not_found', 'No delivery with that id can be retried in this scope.');
        }

        $this->markWebhookDelivery($id, 'pending');

        return new DeliveryRetry($id, 'pending');
    }

    public function listEndpoints(?string $scope = null): array
    {
        $this->record(new RecordedCall(Operation::ListEndpoints, $scope));

        return array_map($this->withoutSecret(...), array_values($this->endpoints[$this->bucket($scope)] ?? []));
    }

    public function createEndpoint(NewEndpoint $endpoint, ?string $idempotencyKey = null, ?string $scope = null): Endpoint
    {
        $this->record(new RecordedCall(Operation::CreateEndpoint, $scope, idempotencyKey: $idempotencyKey, subject: $endpoint));
        $bucket = $this->bucket($scope);
        $fingerprint = $this->fingerprint(Operation::CreateEndpoint, $bucket, $endpoint);
        $replayed = $this->replayed($idempotencyKey, $fingerprint);

        if ($replayed instanceof Endpoint) {
            return $replayed;
        }

        if (! str_starts_with($endpoint->url, 'https://')) {
            throw $this->invalid('url', 'The URL must be a public https address.');
        }

        $number = $this->nextNumber();

        $created = new Endpoint(
            id: 'ep_fake_'.$number,
            url: $endpoint->url,
            eventTypes: $endpoint->eventTypes,
            envelope: $endpoint->envelope->value ?? 'cbox',
            signatureScheme: $endpoint->signatureScheme->value ?? 'cbox',
            description: $endpoint->description,
            createdAt: $this->now(),
            secret: 'fake_secret_'.$number,
        );

        $this->endpoints[$bucket][$created->id] = $created;

        return $this->remember($idempotencyKey, $fingerprint, $created);
    }

    public function findEndpoint(string $id, ?string $scope = null): ?Endpoint
    {
        $this->record(new RecordedCall(Operation::FindEndpoint, $scope, $id));
        $endpoint = $this->endpoints[$this->bucket($scope)][$id] ?? null;

        return $endpoint === null ? null : $this->withoutSecret($endpoint);
    }

    public function updateEndpoint(string $id, EndpointChanges $changes, ?string $scope = null): Endpoint
    {
        $this->record(new RecordedCall(Operation::UpdateEndpoint, $scope, $id, subject: $changes));
        $bucket = $this->bucket($scope);
        $endpoint = $this->endpointIn($bucket, $id);

        if ($changes->url !== null && ! str_starts_with($changes->url, 'https://')) {
            throw $this->invalid('url', 'The URL must be a public https address.');
        }

        return $this->withoutSecret($this->endpoints[$bucket][$id] = $this->changed(
            $endpoint,
            url: $changes->url,
            eventTypes: $changes->eventTypes,
            description: $changes->clearDescription && $changes->description === null ? '' : $changes->description,
            envelope: $changes->envelope?->value,
        ));
    }

    public function deleteEndpoint(string $id, ?string $scope = null): void
    {
        $this->record(new RecordedCall(Operation::DeleteEndpoint, $scope, $id));
        $bucket = $this->bucket($scope);
        $this->endpointIn($bucket, $id);

        unset($this->endpoints[$bucket][$id]);
    }

    public function pauseEndpoint(string $id, ?string $scope = null): Endpoint
    {
        $this->record(new RecordedCall(Operation::PauseEndpoint, $scope, $id));
        $bucket = $this->bucket($scope);

        return $this->withoutSecret($this->endpoints[$bucket][$id] = $this->changed($this->endpointIn($bucket, $id), status: 'paused'));
    }

    public function resumeEndpoint(string $id, ?string $scope = null): Endpoint
    {
        $this->record(new RecordedCall(Operation::ResumeEndpoint, $scope, $id));
        $bucket = $this->bucket($scope);

        return $this->withoutSecret($this->endpoints[$bucket][$id] = $this->changed($this->endpointIn($bucket, $id), status: 'active'));
    }

    public function rotateEndpointSecret(string $id, ?string $scope = null): Endpoint
    {
        $this->record(new RecordedCall(Operation::RotateEndpointSecret, $scope, $id));
        $bucket = $this->bucket($scope);

        $rotated = $this->changed($this->endpointIn($bucket, $id), secret: 'fake_secret_'.$this->nextNumber());
        $this->endpoints[$bucket][$id] = $rotated;

        return new Endpoint(
            $rotated->id, $rotated->url, $rotated->eventTypes, $rotated->status, $rotated->envelope, $rotated->signatureScheme,
            $rotated->description, $rotated->disabledReason, $rotated->lastSequence, $rotated->health, $rotated->createdAt,
            $rotated->secret, $this->now()->modify('+1 day'),
        );
    }

    public function pingEndpoint(string $id, ?string $scope = null): PingResult
    {
        $this->record(new RecordedCall(Operation::PingEndpoint, $scope, $id));
        $this->endpointIn($this->bucket($scope), $id);

        return $this->pingResult;
    }

    public function replayEndpoint(string $id, DateTimeInterface $since, ?DateTimeInterface $until = null, array $types = [], ?string $scope = null): int
    {
        $this->record(new RecordedCall(Operation::ReplayEndpoint, $scope, $id));
        $bucket = $this->bucket($scope);
        $endpoint = $this->endpointIn($bucket, $id);
        $queued = 0;

        foreach ($this->events[$bucket] ?? [] as $event) {
            $at = $event->occurredAt ?? $event->createdAt;

            if ($at === null || $at < $since || ($until !== null && $at > $until) || ($types !== [] && ! in_array($event->type, $types, true)) || ! $endpoint->subscribesTo($event->type)) {
                continue;
            }

            $this->deliver($bucket, $endpoint->id, $event);
            $queued++;
        }

        return $queued;
    }

    /**
     * Store an endpoint as if it had been registered earlier, without recording a call.
     * The scope is stored first if it is not there.
     *
     * @param  list<string>  $eventTypes
     */
    public function seedEndpoint(string $scope, string $url = 'https://example.test/hooks', array $eventTypes = ['*'], string $status = 'active'): Endpoint
    {
        $bucket = ($this->scope($scope) ?? $this->seedScope($scope))->id;
        $number = $this->nextNumber();

        return $this->endpoints[$bucket]['ep_fake_'.$number] = new Endpoint(
            id: 'ep_fake_'.$number,
            url: $url,
            eventTypes: $eventTypes,
            status: $status,
            createdAt: $this->now(),
            secret: 'fake_secret_'.$number,
        );
    }

    /** What every ping answers from now on. The default is a receiver that answered 200. */
    public function pingAnswers(PingResult $result): static
    {
        $this->pingResult = $result;

        return $this;
    }

    /** Set what became of a webhook delivery, as the queue would have. */
    public function markWebhookDelivery(string $id, string $status, ?int $responseCode = null, ?string $error = null): static
    {
        foreach ($this->webhookDeliveries as $bucket => $deliveries) {
            $delivery = $deliveries[$id] ?? null;

            if ($delivery !== null) {
                $this->webhookDeliveries[$bucket][$id] = new WebhookDelivery(
                    $delivery->id, $delivery->endpointId, $delivery->eventId, $delivery->type, $delivery->sequence, $status,
                    $status === 'pending' ? $delivery->attempts : $delivery->attempts + 1,
                    $responseCode, $error, null, $status === 'delivered' ? $this->now() : null, $delivery->createdAt,
                );
            }
        }

        return $this;
    }

    /**
     * The events that were published (duplicates of an idempotency key are not), in order.
     *
     * @return list<NewEvent>
     */
    public function published(?string $type = null): array
    {
        return array_values(array_map(
            static fn (array $published): NewEvent => $published['event'],
            array_filter($this->published, static fn (array $published): bool => $type === null || $published['event']->type === $type),
        ));
    }

    /**
     * An event of that type was published (and matches `$where`).
     *
     * @param  (callable(NewEvent, string|null): bool)|null  $where  given the event and the scope it was published for
     */
    public function assertPublished(string $type, ?callable $where = null): void
    {
        $matching = array_filter(
            $this->published,
            static fn (array $published): bool => $published['event']->type === $type && ($where === null || $where($published['event'], $published['scope'])),
        );

        Assert::assertNotEmpty($matching, $where === null
            ? "No '{$type}' event was published to Cbox Relay."
            : "No '{$type}' event matching the condition was published to Cbox Relay.");
    }

    public function assertPublishedTimes(string $type, int $times): void
    {
        Assert::assertCount($times, $this->published($type), "The event '{$type}' was not published to Cbox Relay {$times} time(s).");
    }

    public function assertNotPublished(string $type): void
    {
        Assert::assertSame([], $this->published($type), "The event '{$type}' was published to Cbox Relay.");
    }

    public function assertNothingPublished(): void
    {
        Assert::assertSame([], array_map(static fn (NewEvent $event): string => $event->type, $this->published()), 'Events were published to Cbox Relay.');
    }

    /**
     * The scope's event types were declared as exactly these names, in any order.
     *
     * @param  list<string>  $types
     */
    public function assertEventTypesDeclared(array $types, ?string $scope = null): void
    {
        $declared = array_map(static fn (EventType $type): string => $type->type, $this->eventTypes[$this->bucketOf($scope)] ?? []);

        sort($declared);
        sort($types);

        Assert::assertSame($types, $declared, 'The event types declared to Cbox Relay are not the expected ones.');
    }

    /**
     * An endpoint with that URL is registered (and matches `$where`).
     *
     * @param  (callable(Endpoint): bool)|null  $where
     */
    public function assertEndpointRegistered(string $url, ?callable $where = null): void
    {
        $matching = [];

        foreach ($this->endpoints as $endpoints) {
            foreach ($endpoints as $endpoint) {
                if ($endpoint->url === $url && ($where === null || $where($endpoint))) {
                    $matching[] = $endpoint;
                }
            }
        }

        Assert::assertNotEmpty($matching, "No webhook endpoint '{$url}'".($where === null ? '' : ' matching the condition').' is registered with Cbox Relay.');
    }

    /** Once a scope has declared types, only those can be published, as on the real service. */
    private function guardDeclared(string $bucket, NewEvent $event): void
    {
        $declared = $this->eventTypes[$bucket] ?? [];

        if ($declared !== [] && array_find($declared, static fn (EventType $type): bool => $type->type === $event->type) === null) {
            throw $this->invalid('type', "The type '{$event->type}' is not one this scope declared.");
        }
    }

    private function store(string $bucket, NewEvent $event, ?string $scope): Published
    {
        if ($event->idempotencyKey !== null) {
            $first = array_find($this->events[$bucket] ?? [], static fn (Event $stored): bool => $stored->idempotencyKey === $event->idempotencyKey);

            if ($first !== null) {
                return new Published($first->id, count(array_filter($this->webhookDeliveries[$bucket] ?? [], static fn (WebhookDelivery $delivery): bool => $delivery->eventId === $first->id)), true);
            }
        }

        $stored = new Event('evt_fake_'.$this->nextNumber(), $event->type, $event->idempotencyKey, $event->occurredAt ?? $this->now(), $this->now(), $event->payload);

        $this->events[$bucket][$stored->id] = $stored;
        $this->published[] = ['event' => $event, 'scope' => $scope, 'id' => $stored->id];

        $deliveries = 0;

        foreach ($this->endpoints[$bucket] ?? [] as $endpoint) {
            if ($endpoint->active() && $endpoint->subscribesTo($event->type)) {
                $this->deliver($bucket, $endpoint->id, $stored);
                $deliveries++;
            }
        }

        return new Published($stored->id, $deliveries, false);
    }

    private function deliver(string $bucket, string $endpointId, Event $event): void
    {
        $endpoint = $this->endpointIn($bucket, $endpointId);
        $sequence = $endpoint->lastSequence + 1;
        $id = 'whd_fake_'.$this->nextNumber();

        $this->endpoints[$bucket][$endpointId] = $this->changed($endpoint, lastSequence: $sequence);
        $this->webhookDeliveries[$bucket][$id] = new WebhookDelivery($id, $endpointId, $event->id, $event->type, $sequence, 'pending', createdAt: $this->now());
    }

    private function endpointIn(string $bucket, string $id): Endpoint
    {
        return $this->endpoints[$bucket][$id] ?? throw $this->missing('endpoint_not_found', 'No webhook endpoint with that id exists in this scope.');
    }

    /** The secret is only in the answer that made or rotated it. */
    private function withoutSecret(Endpoint $endpoint): Endpoint
    {
        return new Endpoint(
            $endpoint->id, $endpoint->url, $endpoint->eventTypes, $endpoint->status, $endpoint->envelope, $endpoint->signatureScheme,
            $endpoint->description, $endpoint->disabledReason, $endpoint->lastSequence, $endpoint->health, $endpoint->createdAt,
        );
    }

    /**
     * @param  list<string>|null  $eventTypes
     * @param  string|null  $description  an empty string clears it
     */
    private function changed(Endpoint $endpoint, ?string $url = null, ?array $eventTypes = null, ?string $description = null, ?string $envelope = null, ?string $status = null, ?int $lastSequence = null, ?string $secret = null): Endpoint
    {
        return new Endpoint(
            id: $endpoint->id,
            url: $url ?? $endpoint->url,
            eventTypes: $eventTypes ?? $endpoint->eventTypes,
            status: $status ?? $endpoint->status,
            envelope: $envelope ?? $endpoint->envelope,
            signatureScheme: $endpoint->signatureScheme,
            description: $description === '' ? null : ($description ?? $endpoint->description),
            disabledReason: $endpoint->disabledReason,
            lastSequence: $lastSequence ?? $endpoint->lastSequence,
            health: new EndpointHealth,
            createdAt: $endpoint->createdAt,
            secret: $secret ?? $endpoint->secret,
        );
    }
}
