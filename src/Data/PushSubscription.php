<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * A browser Relay remembers for a project. `$ref` is your own reference for whose browser
 * it is; `$service` is the push service's host. The endpoint itself is never given back.
 *
 * `$created` is true only on the answer that added a new browser (Relay answered 201).
 */
readonly class PushSubscription
{
    public function __construct(
        public string $id,
        public string $service,
        public DateTimeImmutable $createdAt,
        public ?string $ref = null,
        public ?DateTimeImmutable $lastDeliveredAt = null,
        public bool $created = false,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, bool $created = false): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            service: Payload::string($data, 'service'),
            createdAt: Payload::date($data, 'created_at'),
            ref: Payload::nullableString($data, 'ref'),
            lastDeliveredAt: Payload::nullableDate($data, 'last_delivered_at'),
            created: $created,
        );
    }
}
