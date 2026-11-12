<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\ProjectLimitRequestDecided as ProjectLimitRequestDecidedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `project.limit_request_decided` delivery: `$event` is what it says, typed. */
readonly class ProjectLimitRequestDecided implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public ProjectLimitRequestDecidedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
