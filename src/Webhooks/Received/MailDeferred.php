<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\Events\MailDeferred as MailDeferredData;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The Laravel event for a verified `mail.deferred` delivery: `$event` is what it says, typed. */
readonly class MailDeferred implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public MailDeferredData $event,
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
