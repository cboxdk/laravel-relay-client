<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\MailDelivered as MailDeliveredData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `mail.delivered` delivery: `$event` is what it says, typed. */
readonly class MailDelivered implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public MailDeliveredData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
