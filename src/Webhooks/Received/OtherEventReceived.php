<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Contracts\ReceivedWebhook;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/**
 * The Laravel event for every delivery that is not one of Relay's own typed events: an
 * event your product published and subscribed to itself, or a type Relay added after
 * this version of the package. Read `$webhook->type` and `$webhook->data`.
 */
readonly class OtherEventReceived implements ReceivedWebhook
{
    use CarriesWebhook;

    public function __construct(
        public IncomingWebhook $webhook,
        public ?string $connection = null,
    ) {}
}
