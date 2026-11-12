<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `project.limit_requested`: higher sending limits were asked for. `$hour` and `$day` are
 * what was asked, in recipients; `Relay::sendingLimits()->findRequest($event->requestId)`
 * reads the request.
 */
readonly class ProjectLimitRequested implements RelayEvent
{
    public function __construct(
        public string $projectId,
        public string $requestId,
        public int $hour = 0,
        public int $day = 0,
    ) {}

    public static function type(): string
    {
        return 'project.limit_requested';
    }

    public static function fromArray(array $data): self
    {
        return new self(Payload::string($data, 'project_id'), Payload::string($data, 'request_id'), Payload::int($data, 'hour'), Payload::int($data, 'day'));
    }
}
