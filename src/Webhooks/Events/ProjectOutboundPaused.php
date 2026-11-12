<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `project.outbound_paused`: Relay paused the project's outbound mail for too many bounces
 * or complaints. `$sent`, `$bounced` and `$complained` are the counts it judged on.
 * Relay's operator lifts the pause.
 */
readonly class ProjectOutboundPaused implements RelayEvent
{
    public function __construct(
        public string $projectId,
        public string $reason = '',
        public int $sent = 0,
        public int $bounced = 0,
        public int $complained = 0,
    ) {}

    public static function type(): string
    {
        return 'project.outbound_paused';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'project_id'),
            Payload::string($data, 'reason'),
            Payload::int($data, 'sent'),
            Payload::int($data, 'bounced'),
            Payload::int($data, 'complained'),
        );
    }
}
