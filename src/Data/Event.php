<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * A published event. A list gives summaries: `$payload` is null and `$deliveries`
 * empty there. One event fetched by id carries both.
 */
readonly class Event
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @param  list<WebhookDelivery>  $deliveries
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $idempotencyKey = null,
        public ?DateTimeImmutable $occurredAt = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?array $payload = null,
        public array $deliveries = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            type: Payload::string($data, 'type'),
            idempotencyKey: Payload::nullableString($data, 'idempotency_key'),
            occurredAt: Payload::nullableDate($data, 'occurred_at'),
            createdAt: Payload::nullableDate($data, 'created_at'),
            payload: Payload::nullableMap($data, 'payload'),
            deliveries: array_map(WebhookDelivery::fromArray(...), Payload::optionalMaps($data, 'deliveries')),
        );
    }
}
