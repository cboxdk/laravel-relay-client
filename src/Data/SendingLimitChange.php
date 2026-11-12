<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/** One move of a project's sending limits: what they became, what they were, who moved them and why. */
readonly class SendingLimitChange
{
    public function __construct(
        public string $origin,
        public int $hour,
        public int $day,
        public int $previousHour,
        public int $previousDay,
        public string $reason,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            origin: Payload::string($data, 'origin'),
            hour: Payload::int($data, 'hour'),
            day: Payload::int($data, 'day'),
            previousHour: Payload::int($data, 'previous_hour'),
            previousDay: Payload::int($data, 'previous_day'),
            reason: Payload::string($data, 'reason'),
            createdAt: Payload::date($data, 'created_at'),
        );
    }
}
