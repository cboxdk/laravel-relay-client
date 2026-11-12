<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Contracts;

use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/**
 * Every Laravel event the package's webhook route dispatches. Listen for this interface
 * to hear all of them; listen for one class (`Webhooks\Received\MailBounced`) to hear one.
 */
interface ReceivedWebhook
{
    /** The verified delivery: its ids, type, raw data and when it happened. */
    public function webhook(): IncomingWebhook;

    /** The Relay connection the delivery was verified for; null for the default. */
    public function connection(): ?string;
}
