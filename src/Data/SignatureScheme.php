<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** How deliveries to a webhook endpoint are signed. It is chosen when the endpoint is registered and cannot be changed after. */
enum SignatureScheme: string
{
    case Cbox = 'cbox';
    case StandardWebhooks = 'standard-webhooks';
}
