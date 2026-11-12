<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `project.outbound_limits_changed`: the project's sending limits moved. `$origin` says
 * who moved them: `auto` (Relay's daily look at the project's own normal use), `request`
 * (a granted request) or `operator`. All four numbers are recipients.
 */
readonly class ProjectOutboundLimitsChanged implements RelayEvent
{
    public function __construct(
        public string $projectId,
        public string $origin,
        public int $hour,
        public int $day,
        public int $previousHour = 0,
        public int $previousDay = 0,
        public string $reason = '',
    ) {}

    public static function type(): string
    {
        return 'project.outbound_limits_changed';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            projectId: Payload::string($data, 'project_id'),
            origin: Payload::string($data, 'origin'),
            hour: Payload::int($data, 'hour'),
            day: Payload::int($data, 'day'),
            previousHour: Payload::int($data, 'previous_hour'),
            previousDay: Payload::int($data, 'previous_day'),
            reason: Payload::string($data, 'reason'),
        );
    }
}
