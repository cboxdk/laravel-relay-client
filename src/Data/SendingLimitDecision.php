<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * The answer to a request for higher limits. `$hour` and `$day` are what was granted,
 * which may differ from what was asked; both are null when it was declined. `$decidedBy`
 * is `auto` (granted at once, inside Relay's safe bounds) or `operator`.
 */
readonly class SendingLimitDecision
{
    public function __construct(
        public ?int $hour = null,
        public ?int $day = null,
        public ?string $reason = null,
        public ?string $decidedBy = null,
        public ?DateTimeImmutable $decidedAt = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            hour: Payload::nullableInt($data, 'hour'),
            day: Payload::nullableInt($data, 'day'),
            reason: Payload::nullableString($data, 'reason'),
            decidedBy: Payload::nullableString($data, 'decided_by'),
            decidedAt: Payload::nullableDate($data, 'decided_at'),
        );
    }
}
