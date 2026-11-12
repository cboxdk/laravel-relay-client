<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\MailBounced as MailBouncedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `mail.bounced` delivery: `$event` is what it says, typed. */
readonly class MailBounced implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public MailBouncedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
