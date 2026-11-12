<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\PushExpired as PushExpiredData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `push.expired` delivery: `$event` is what it says, typed. */
readonly class PushExpired implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public PushExpiredData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
