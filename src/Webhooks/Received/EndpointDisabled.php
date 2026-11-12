<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\EndpointDisabled as EndpointDisabledData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `endpoint.disabled` delivery: `$event` is what it says, typed. */
readonly class EndpointDisabled implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public EndpointDisabledData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
