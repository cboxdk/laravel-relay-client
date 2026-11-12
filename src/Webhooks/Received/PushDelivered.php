<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\PushDelivered as PushDeliveredData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `push.delivered` delivery: `$event` is what it says, typed. */
readonly class PushDelivered implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public PushDeliveredData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
