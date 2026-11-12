<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\MailBlocked as MailBlockedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `mail.blocked` delivery: `$event` is what it says, typed. */
readonly class MailBlocked implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public MailBlockedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
