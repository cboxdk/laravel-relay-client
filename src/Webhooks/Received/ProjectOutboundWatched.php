<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\ProjectOutboundWatched as ProjectOutboundWatchedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `project.outbound_watched` delivery: `$event` is what it says, typed. */
readonly class ProjectOutboundWatched implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public ProjectOutboundWatchedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
