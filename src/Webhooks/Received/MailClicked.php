<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\MailClicked as MailClickedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `mail.clicked` delivery: `$event` is what it says, typed. */
readonly class MailClicked implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public MailClickedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
