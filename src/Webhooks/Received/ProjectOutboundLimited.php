<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\ProjectOutboundLimited as ProjectOutboundLimitedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `project.outbound_limited` delivery: `$event` is what it says, typed. */
readonly class ProjectOutboundLimited implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public ProjectOutboundLimitedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
