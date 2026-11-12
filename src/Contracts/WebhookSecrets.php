<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Contracts;

use Illuminate\Http\Request;

/**
 * Which signing secrets an incoming webhook from Relay may carry. The default reads
 * `relay.webhook.secret` and `relay.webhook.previous_secret`. Bind your own when each
 * of your projects has an endpoint (and so a secret) of its own: pick by route parameter,
 * by host, or by whatever tells them apart.
 */
interface WebhookSecrets
{
    /**
     * @return list<string> the current secret first, then any still valid from a rotation; empty refuses the delivery
     */
    public function for(Request $request): array;
}
