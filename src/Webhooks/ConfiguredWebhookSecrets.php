<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks;

use Cbox\Relay\Client\Contracts\WebhookSecrets;
use Cbox\Relay\Client\RelayConfig;
use Illuminate\Http\Request;

/** One secret set for every incoming webhook, from `relay.webhook`. */
class ConfiguredWebhookSecrets implements WebhookSecrets
{
    public function __construct(private readonly RelayConfig $config) {}

    public function for(Request $request): array
    {
        return $this->config->webhookSecrets();
    }
}
