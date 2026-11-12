<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks;

use Cbox\Relay\Client\Contracts\WebhookSecrets;
use Cbox\Relay\Client\Exceptions\RelayNotConfigured;
use Cbox\Relay\Client\RelayConfig;
use Illuminate\Http\Request;

/**
 * The secrets of the connection the request is for: the route parameter `connection`
 * when the package's own webhook route carries one, else the default (`relay.webhook`).
 * A name that is no connection has no secrets, so the delivery is refused.
 */
class ConfiguredWebhookSecrets implements WebhookSecrets
{
    public function __construct(private readonly RelayConfig $config) {}

    public function for(Request $request): array
    {
        $connection = $request->route('connection');

        if (! is_string($connection) || $connection === '') {
            return $this->config->webhookSecrets();
        }

        try {
            return $this->config->connection($connection)->webhookSecrets;
        } catch (RelayNotConfigured) {
            return [];
        }
    }
}
