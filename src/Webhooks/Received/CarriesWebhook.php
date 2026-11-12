<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Received;

use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** The two answers every received webhook gives, read from its own properties. */
trait CarriesWebhook
{
    public function webhook(): IncomingWebhook
    {
        return $this->webhook;
    }

    public function connection(): ?string
    {
        return $this->connection;
    }
}
