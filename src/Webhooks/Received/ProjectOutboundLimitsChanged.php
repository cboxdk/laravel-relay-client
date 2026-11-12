<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\ProjectOutboundLimitsChanged as ProjectOutboundLimitsChangedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `project.outbound_limits_changed` delivery: `$event` is what it says, typed. */
readonly class ProjectOutboundLimitsChanged implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public ProjectOutboundLimitsChangedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
