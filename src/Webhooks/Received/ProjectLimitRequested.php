<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\ProjectLimitRequested as ProjectLimitRequestedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `project.limit_requested` delivery: `$event` is what it says, typed. */
readonly class ProjectLimitRequested implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public ProjectLimitRequestedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
