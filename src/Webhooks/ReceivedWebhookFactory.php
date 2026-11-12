<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Exceptions\RelayWebhookRejected;
use Cbox\Relay\Client\Webhooks\Events as Data;
use Cbox\Relay\Client\Webhooks\Received\OtherEventReceived;

/** Turns a verified delivery into the Laravel event the webhook route dispatches for it. */
class ReceivedWebhookFactory
{
    /**
     * @throws RelayWebhookRejected with `REASON_MALFORMED_BODY` when the data is not what that event carries
     */
    public function make(IncomingWebhook $webhook, ?string $connection = null): ReceivedWebhook
    {
        $event = $webhook->event();

        return match (true) {
            $event instanceof Data\MailReceived => new Received\MailReceived($event, $webhook, $connection),
            $event instanceof Data\MailAccepted => new Received\MailAccepted($event, $webhook, $connection),
            $event instanceof Data\MailBlocked => new Received\MailBlocked($event, $webhook, $connection),
            $event instanceof Data\MailDelivered => new Received\MailDelivered($event, $webhook, $connection),
            $event instanceof Data\MailBounced => new Received\MailBounced($event, $webhook, $connection),
            $event instanceof Data\MailComplained => new Received\MailComplained($event, $webhook, $connection),
            $event instanceof Data\MailClicked => new Received\MailClicked($event, $webhook, $connection),
            $event instanceof Data\PushDelivered => new Received\PushDelivered($event, $webhook, $connection),
            $event instanceof Data\PushExpired => new Received\PushExpired($event, $webhook, $connection),
            $event instanceof Data\EndpointDisabled => new Received\EndpointDisabled($event, $webhook, $connection),
            $event instanceof Data\ProjectOutboundPaused => new Received\ProjectOutboundPaused($event, $webhook, $connection),
            default => new OtherEventReceived($webhook, $connection),
        };
    }
}
