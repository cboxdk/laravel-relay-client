<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * A request for higher sending limits. `$status` is `pending` (Relay's operator has not
 * answered), `approved` or `declined`; `$hour` and `$day` are what was asked for, and
 * `$decision` holds the answer once there is one.
 */
readonly class SendingLimitRequest
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $status,
        public int $hour,
        public int $day,
        public string $reason,
        public DateTimeImmutable $createdAt,
        public ?SendingLimitDecision $decision = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $decision = Payload::nullableMap($data, 'decision');

        return new self(
            id: Payload::string($data, 'id'),
            projectId: Payload::string($data, 'project_id'),
            status: Payload::string($data, 'status'),
            hour: Payload::int($data, 'hour'),
            day: Payload::int($data, 'day'),
            reason: Payload::string($data, 'reason'),
            createdAt: Payload::date($data, 'created_at'),
            decision: $decision === null ? null : SendingLimitDecision::fromArray($decision),
        );
    }

    /** Whether it still waits for Relay's operator. */
    public function pending(): bool
    {
        return $this->status === 'pending';
    }

    /** Whether it was granted, at once or by the operator. */
    public function approved(): bool
    {
        return $this->status === 'approved';
    }

    /** Whether it was declined; `$decision->reason` says why. */
    public function declined(): bool
    {
        return $this->status === 'declined';
    }
}
