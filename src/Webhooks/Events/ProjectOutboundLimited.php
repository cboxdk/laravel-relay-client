<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `project.outbound_limited`: a sending limit was reached, and more mail is refused until
 * there is room. Sent once an hour at most. `$scope` says whose limit: `project`, or
 * `organization` when it was the one for all the organization's projects together.
 * `$window` is `hour` or `day`; `$limit` and `$used` are in recipients.
 */
readonly class ProjectOutboundLimited implements RelayEvent
{
    public function __construct(
        public string $projectId,
        public string $scope = 'project',
        public string $window = 'hour',
        public int $limit = 0,
        public int $used = 0,
    ) {}

    public static function type(): string
    {
        return 'project.outbound_limited';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            projectId: Payload::string($data, 'project_id'),
            // An event from before Relay had organization limits says nothing: it was the project's.
            scope: Payload::nullableString($data, 'scope') ?? 'project',
            window: Payload::string($data, 'window'),
            limit: Payload::int($data, 'limit'),
            used: Payload::int($data, 'used'),
        );
    }
}
