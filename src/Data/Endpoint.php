<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * Where a scope's webhooks are sent. `$secret` is only set on the answer that created
 * the endpoint or rotated its secret: store it then, Relay never gives it again.
 * `$status`, `$envelope` and `$signatureScheme` are Relay's own strings.
 */
readonly class Endpoint
{
    /**
     * @param  list<string>  $eventTypes
     */
    public function __construct(
        public string $id,
        public string $url,
        public array $eventTypes = [],
        public string $status = 'active',
        public string $envelope = 'cbox',
        public string $signatureScheme = 'cbox',
        public ?string $description = null,
        public ?string $disabledReason = null,
        public int $lastSequence = 0,
        public EndpointHealth $health = new EndpointHealth,
        public ?DateTimeImmutable $createdAt = null,
        public ?string $secret = null,
        public ?DateTimeImmutable $previousSecretExpiresAt = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            url: Payload::string($data, 'url'),
            eventTypes: Payload::strings($data, 'event_types'),
            status: Payload::string($data, 'status'),
            envelope: Payload::string($data, 'envelope'),
            signatureScheme: Payload::string($data, 'signature_scheme'),
            description: Payload::nullableString($data, 'description'),
            disabledReason: Payload::nullableString($data, 'disabled_reason'),
            lastSequence: Payload::int($data, 'last_sequence'),
            health: EndpointHealth::fromArray(Payload::nullableMap($data, 'health') ?? []),
            createdAt: Payload::nullableDate($data, 'created_at'),
            secret: Payload::nullableString($data, 'secret'),
            previousSecretExpiresAt: Payload::nullableDate($data, 'previous_secret_expires_at'),
        );
    }

    public function active(): bool
    {
        return $this->status === 'active';
    }

    /** Whether this endpoint subscribes to `$type`: by name, by group (`order.*`) or by `*`. */
    public function subscribesTo(string $type): bool
    {
        foreach ($this->eventTypes as $pattern) {
            if ($pattern === '*' || $pattern === $type || (str_ends_with($pattern, '.*') && str_starts_with($type, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
}
