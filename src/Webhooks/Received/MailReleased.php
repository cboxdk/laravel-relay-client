<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\MailReleased as MailReleasedData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `mail.released` delivery: `$event` is what it says, typed. */
readonly class MailReleased implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public MailReleasedData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
