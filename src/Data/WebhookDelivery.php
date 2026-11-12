<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * One event on its way to one endpoint. `$sequence` counts up by one per endpoint.
 * `$status` is Relay's own string (`pending`, `in_flight`, `delivered`, `failed`,
 * `exhausted`, `cancelled` today), passed through as it is.
 */
readonly class WebhookDelivery
{
    public function __construct(
        public string $id,
        public string $endpointId,
        public string $eventId,
        public string $type,
        public int $sequence,
        public string $status,
        public int $attempts = 0,
        public ?int $lastResponseCode = null,
        public ?string $lastError = null,
        public ?DateTimeImmutable $nextAttemptAt = null,
        public ?DateTimeImmutable $deliveredAt = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            endpointId: Payload::string($data, 'endpoint_id'),
            eventId: Payload::string($data, 'event_id'),
            type: Payload::string($data, 'type'),
            sequence: Payload::int($data, 'sequence'),
            status: Payload::string($data, 'status'),
            attempts: Payload::int($data, 'attempts'),
            lastResponseCode: Payload::nullableInt($data, 'last_response_code'),
            lastError: Payload::nullableString($data, 'last_error'),
            nextAttemptAt: Payload::nullableDate($data, 'next_attempt_at'),
            deliveredAt: Payload::nullableDate($data, 'delivered_at'),
            createdAt: Payload::nullableDate($data, 'created_at'),
        );
    }
}
