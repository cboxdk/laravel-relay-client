<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\MailReceived as MailReceivedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `mail.received` delivery: `$event` is what it says, typed. */
readonly class MailReceived implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public MailReceivedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
