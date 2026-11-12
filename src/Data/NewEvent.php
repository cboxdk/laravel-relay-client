<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;
use DateTimeInterface;
use stdClass;

/**
 * An event to publish. `$idempotencyKey` is what makes a repeat harmless: Relay stores
 * the first event with a key and answers every later one with it as a duplicate.
 */
readonly class NewEvent
{
    public ?DateTimeImmutable $occurredAt;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public array $payload = [],
        public ?string $idempotencyKey = null,
        ?DateTimeInterface $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt === null ? null : DateTimeImmutable::createFromInterface($occurredAt);
    }

    public function withIdempotencyKey(string $key): self
    {
        return new self($this->type, $this->payload, $key, $this->occurredAt);
    }

    /**
     * The request body. An empty payload goes out as the JSON object `{}`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Payload::compact([
            'type' => $this->type,
            'payload' => $this->payload === [] ? new stdClass : $this->payload,
            'idempotency_key' => $this->idempotencyKey,
            'occurred_at' => $this->occurredAt === null ? null : Payload::iso($this->occurredAt),
        ]);
    }
}
