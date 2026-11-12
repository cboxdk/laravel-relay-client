<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\MailComplained as MailComplainedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `mail.complained` delivery: `$event` is what it says, typed. */
readonly class MailComplained implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public MailComplainedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
