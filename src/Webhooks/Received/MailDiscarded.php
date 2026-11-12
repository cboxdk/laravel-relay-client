<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\MailDiscarded as MailDiscardedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `mail.discarded` delivery: `$event` is what it says, typed. */
readonly class MailDiscarded implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public MailDiscardedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
