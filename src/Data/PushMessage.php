<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * A push message and what became of it. `$status` is Relay's own string (`queued`,
 * `delivered`, `expired`, `failed` today). `delivered` means the push service took the
 * message; whether the device showed it nobody is told.
 */
readonly class PushMessage
{
    public function __construct(
        public string $id,
        public string $subscriptionId,
        public string $status,
        public DateTimeImmutable $createdAt,
        public int $attempts = 0,
        public ?int $responseCode = null,
        public ?string $error = null,
        public ?DateTimeImmutable $finishedAt = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            subscriptionId: Payload::string($data, 'subscription_id'),
            status: Payload::string($data, 'status'),
            createdAt: Payload::date($data, 'created_at'),
            attempts: Payload::int($data, 'attempts'),
            responseCode: Payload::nullableInt($data, 'response_code'),
            error: Payload::nullableString($data, 'error'),
            finishedAt: Payload::nullableDate($data, 'finished_at'),
        );
    }
}
