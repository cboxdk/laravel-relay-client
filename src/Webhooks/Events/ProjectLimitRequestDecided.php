<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `project.limit_request_decided`: a request for higher limits was answered. `$status` is
 * `approved` or `declined`; `$hour` and `$day` are what was granted (null when declined),
 * `$reason` why, and `$decidedBy` is `auto` (granted at once) or `operator`.
 */
readonly class ProjectLimitRequestDecided implements RelayEvent
{
    public function __construct(
        public string $projectId,
        public string $requestId,
        public string $status,
        public ?int $hour = null,
        public ?int $day = null,
        public ?string $reason = null,
        public ?string $decidedBy = null,
    ) {}

    public function approved(): bool
    {
        return $this->status === 'approved';
    }

    public static function type(): string
    {
        return 'project.limit_request_decided';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            projectId: Payload::string($data, 'project_id'),
            requestId: Payload::string($data, 'request_id'),
            status: Payload::string($data, 'status'),
            hour: Payload::nullableInt($data, 'hour'),
            day: Payload::nullableInt($data, 'day'),
            reason: Payload::nullableString($data, 'reason'),
            decidedBy: Payload::nullableString($data, 'decided_by'),
        );
    }
}
