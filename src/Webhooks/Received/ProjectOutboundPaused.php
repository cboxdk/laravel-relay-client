<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\ProjectOutboundPaused as ProjectOutboundPausedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `project.outbound_paused` delivery: `$event` is what it says, typed. */
readonly class ProjectOutboundPaused implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public ProjectOutboundPausedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
