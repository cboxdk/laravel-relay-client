<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * A provider a project takes webhooks from. Give `$url` to the provider as its webhook
 * address; what it sends there is verified, deduplicated and forwarded to the project's
 * own endpoints as the event type `$eventType` (`inbound.<name>`).
 *
 * The provider's signing secret is never given back. `$scheme` and `$status` (`active`
 * or `paused` today) are Relay's own strings.
 */
readonly class Source
{
    public function __construct(
        public string $id,
        public string $name,
        public string $scheme,
        public string $url,
        public string $eventType,
        public string $status = 'active',
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $previousSecretExpiresAt = null,
        public ?DateTimeImmutable $lastReceivedAt = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            name: Payload::string($data, 'name'),
            scheme: Payload::string($data, 'scheme'),
            url: Payload::string($data, 'url'),
            eventType: Payload::string($data, 'event_type'),
            status: Payload::string($data, 'status'),
            createdAt: Payload::date($data, 'created_at'),
            previousSecretExpiresAt: Payload::nullableDate($data, 'previous_secret_expires_at'),
            lastReceivedAt: Payload::nullableDate($data, 'last_received_at'),
        );
    }

    public function active(): bool
    {
        return $this->status === 'active';
    }
}
