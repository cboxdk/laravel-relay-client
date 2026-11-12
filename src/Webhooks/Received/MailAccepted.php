<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\MailAccepted as MailAcceptedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `mail.accepted` delivery: `$event` is what it says, typed. */
readonly class MailAccepted implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public MailAcceptedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
