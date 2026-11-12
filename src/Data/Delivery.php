<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * One line of the delivery log: one attempt to deliver one thing to one target.
 * `$channel` and `$status` are Relay's own strings, passed through as they are: Relay
 * adds channels and statuses over time and this client does not pretend to know the set.
 */
readonly class Delivery
{
    public function __construct(
        public string $id,
        public string $channel,
        public string $subjectType,
        public string $subjectId,
        public string $target,
        public string $status,
        public DateTimeImmutable $occurredAt,
        public ?string $scopeId = null,
        public int $attempt = 1,
        public ?int $responseCode = null,
        public ?string $error = null,
        public ?int $durationMs = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            channel: Payload::string($data, 'channel'),
            subjectType: Payload::string($data, 'subject_type'),
            subjectId: Payload::string($data, 'subject_id'),
            target: Payload::string($data, 'target'),
            status: Payload::string($data, 'status'),
            occurredAt: Payload::date($data, 'occurred_at'),
            scopeId: Payload::nullableString($data, 'scope_id'),
            attempt: Payload::int($data, 'attempt'),
            responseCode: Payload::nullableInt($data, 'response_code'),
            error: Payload::nullableString($data, 'error'),
            durationMs: Payload::nullableInt($data, 'duration_ms'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'scope_id' => $this->scopeId,
            'channel' => $this->channel,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'target' => $this->target,
            'status' => $this->status,
            'attempt' => $this->attempt,
            'response_code' => $this->responseCode,
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::ATOM),
        ];
    }
}
