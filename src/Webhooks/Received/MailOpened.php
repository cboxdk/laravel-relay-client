<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\MailOpened as MailOpenedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `mail.opened` delivery: `$event` is what it says, typed. */
readonly class MailOpened implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public MailOpenedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
