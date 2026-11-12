<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `project.outbound_paused`: Relay paused the project's outbound mail. `$cause` says for
 * what: `rates` (too many bounces or complaints, with `$sent`, `$bounced` and
 * `$complained` as the counts it judged on), `bounces` (a burst of hard bounces) or
 * `held_mail` (several mails Relay held); null on an event from before Relay sent it.
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
        public ?string $cause = null,
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
            isset($data['sent']) ? Payload::int($data, 'sent') : 0,
            isset($data['bounced']) ? Payload::int($data, 'bounced') : 0,
            isset($data['complained']) ? Payload::int($data, 'complained') : 0,
            Payload::nullableString($data, 'cause'),
        );
    }
}
